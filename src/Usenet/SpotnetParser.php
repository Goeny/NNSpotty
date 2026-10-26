<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class SpotnetParser
{
    public const DEFAULT_GROUPS = 'spots:free.pt,comments:free.usenet';

    /** @return array<string, string> */
    public static function groups(string $configuration): array
    {
        $groups = [];
        $configuration = trim($configuration);
        foreach (explode(',', $configuration === '' ? self::DEFAULT_GROUPS : $configuration) as $entry) {
            $parts = array_map('trim', explode(':', $entry, 2));
            if (count($parts) !== 2 || !in_array($parts[0], ['spots', 'comments'], true)
                || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,150}$/D', $parts[1]) !== 1) {
                throw new \InvalidArgumentException('Gebruik spots:free.pt,comments:free.usenet; algemene nieuwsgroep-discovery is geen Spotnet-index.');
            }
            if (isset($groups[$parts[1]])) {
                throw new \InvalidArgumentException('Een nieuwsgroep mag maar een Spotnet-rol hebben.');
            }
            if ($parts[0] === 'comments' && $parts[1] === 'free.ptd') {
                throw new \InvalidArgumentException('free.ptd is niet de standaard reactiegroep. Gebruik comments:free.usenet.');
            }
            $groups[$parts[1]] = $parts[0];
        }
        if (!in_array('spots', $groups, true)) {
            throw new \InvalidArgumentException('Configureer minimaal een spots-groep, bijvoorbeeld spots:free.pt.');
        }
        uasort($groups, static fn (string $a, string $b): int => ($a === 'spots' ? 0 : 1) <=> ($b === 'spots' ? 0 : 1));

        return $groups;
    }

    public static function messageId(string $value): string
    {
        $value = trim($value);
        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = substr($value, 1, -1);
        }
        if (strlen($value) > 250 || preg_match('/^[^\s<>@\x00-\x20\x7f-\xff]+@[^\s<>@\x00-\x20\x7f-\xff]+$/D', $value) !== 1) {
            throw new UnexpectedValueException('Ongeldig Spotnet message-id.');
        }

        return $value;
    }

    /** @return array<string, list<string>> */
    public static function headers(string $raw): array
    {
        $headers = [];
        $name = null;
        foreach (preg_split('/\r\n|\n|\r/', $raw) as $line) {
            if ($line === '') {
                break;
            }
            if (($line[0] === ' ' || $line[0] === "\t") && $name !== null) {
                $last = count($headers[$name]) - 1;
                $headers[$name][$last] .= ' ' . ltrim($line);
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                throw new UnexpectedValueException('Ongeldige NNTP header.');
            }
            $name = strtolower(trim($parts[0]));
            $headers[$name][] = ltrim($parts[1]);
        }

        return $headers;
    }

    /** @return array<string, mixed>|null */
    public static function spot(string $messageId, string $rawHeaders): ?array
    {
        $headers = self::headers($rawHeaders);
        if (!isset($headers['x-xml'])) {
            return null;
        }
        if (isset($headers['message-id'][0]) && self::messageId($headers['message-id'][0]) !== self::messageId($messageId)) {
            throw new UnexpectedValueException('Spot header verwijst naar een ander message-ID.');
        }
        $xmlText = implode('', $headers['x-xml']);
        if (strlen($xmlText) > 51200 || preg_match('/<!DOCTYPE|<!ENTITY/i', $xmlText)) {
            throw new UnexpectedValueException('Spot XML is te groot of bevat verboden declaraties.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($xmlText, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false || $xml->getName() !== 'Spotnet' || count($xml->Posting) !== 1) {
                throw new UnexpectedValueException('Ongeldige Spotnet XML.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $posting = $xml->Posting;
        $title = html_entity_decode(self::text((string) $posting->Title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $categoryNumber = trim((string) $posting->Category);
        if ($title === '' || !ctype_digit($categoryNumber)) {
            throw new UnexpectedValueException('Spot mist een titel of geldige categorie.');
        }
        $categoryIndex = (int) $categoryNumber - ((string) $posting->Key === '1' ? 0 : 1);
        $categories = [0 => 'Beeld', 1 => 'Geluid', 2 => 'Spellen', 3 => 'Applicaties'];
        $size = trim((string) $posting->Size);
        if ($size !== '' && (!ctype_digit($size) || strlen($size) > 18)) {
            throw new UnexpectedValueException('Ongeldige bestandsgrootte in spot.');
        }
        $subcategories = [];
        foreach ($posting->Category->children() as $subcategory) {
            $subcategories[] = (string) $subcategory;
        }
        $newsreader = self::text($headers['x-newsreader'][0] ?? (string) $xml->Extra->Newsreader);
        if (strlen($newsreader) > 512) {
            throw new UnexpectedValueException('Newsreader-veld is te lang.');
        }

        return [
            'id' => hash('sha256', self::messageId($messageId)),
            'message_id' => self::messageId($messageId),
            'title' => $title,
            'description' => html_entity_decode(self::text((string) $posting->Description), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'poster' => self::text((string) $posting->Poster),
            'newsreader' => $newsreader,
            'category' => $categories[$categoryIndex] ?? 'Overig',
            'subcategories' => $subcategories,
            'size' => $size === '' ? 0 : (int) $size,
            'posted_at' => self::date($headers['date'][0] ?? ''),
            'website' => self::webUrl((string) $posting->Website),
            'tag' => self::text((string) $posting->Tag),
            'image_url' => isset($posting->Image->Segment) ? '' : self::webUrl((string) $posting->Image),
            'image_segments' => self::segments($posting->Image),
            'nzb_segments' => self::segments($posting->NZB),
            'xml' => $xmlText,
            ...\NnSpotty\Moderation\SpotterIdentity::fromHeaders($headers, self::messageId($messageId)),
        ];
    }

    /** @return array<string, mixed> */
    public static function comment(string $messageId, string $article): array
    {
        $parts = preg_split('/\r?\n\r?\n/', $article, 2);
        if (count($parts) !== 2 || strlen($parts[1]) > 65536) {
            throw new UnexpectedValueException('Reactie mist een body of is te groot.');
        }
        $headers = self::headers($parts[0]);
        $spotMessageId = self::commentSpot($messageId, $headers['references'][0] ?? '')
            ?? throw new UnexpectedValueException('Reactie mist een verwijzing naar een spot.');
        if (isset($headers['message-id'][0]) && self::messageId($headers['message-id'][0]) !== self::messageId($messageId)) {
            throw new UnexpectedValueException('Reactie verwijst naar een ander message-ID.');
        }
        $body = $parts[1];
        $encoding = strtolower($headers['content-transfer-encoding'][0] ?? '');
        if ($encoding === 'base64') {
            $decoded = base64_decode($body, true);
            if ($decoded === false) {
                throw new UnexpectedValueException('Ongeldige base64 reactie.');
            }
            $body = $decoded;
        } elseif ($encoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif (!in_array($encoding, ['', '7bit', '8bit', 'binary'], true)) {
            throw new UnexpectedValueException('Niet-ondersteunde encoding voor reactie.');
        }
        $contentType = $headers['content-type'][0] ?? 'text/plain';
        if (preg_match('/charset\s*=\s*["\']?([^;"\'\s]+)/i', $contentType, $charset)) {
            $body = self::convertToUtf8($body, $charset[1]);
        }
        $body = self::text($body);
        if (trim($body) === '') {
            throw new UnexpectedValueException('Lege Spotnet reactie.');
        }
        $identity = \NnSpotty\Moderation\SpotterIdentity::fromCommentHeaders(
            $headers,
            self::messageId($messageId),
            $parts[1]
        );
        return [
            'id' => hash('sha256', self::messageId($messageId)),
            'message_id' => self::messageId($messageId),
            'spot_id' => hash('sha256', $spotMessageId),
            'poster' => self::commentPoster($headers['from'][0] ?? ''),
            'spotter_id' => $identity['spotter_id'],
            'signature_state' => $identity['signature_state'],
            'body' => $body,
            'posted_at' => self::date($headers['date'][0] ?? ''),
            'body_status' => 'loaded',
        ];
    }

    /**
     * Builds a comment index entry from XOVER fields only; the body is fetched later on demand.
     * Returns null when the spot cannot be derived without reading the article itself.
     *
     * @return array<string, mixed>|null
     */
    public static function commentStub(string $messageId, string $references, string $from, string $date): ?array
    {
        $spotMessageId = self::commentSpot($messageId, $references);
        if ($spotMessageId === null) {
            return null;
        }

        return [
            'id' => hash('sha256', self::messageId($messageId)),
            'message_id' => self::messageId($messageId),
            'spot_id' => hash('sha256', $spotMessageId),
            'poster' => self::commentPoster($from),
            'body' => null,
            'signature_state' => 'unknown',
            'posted_at' => self::date($date),
            'body_status' => 'pending',
        ];
    }

    private static function commentSpot(string $messageId, string $references): ?string
    {
        preg_match_all('/<([^<>]+)>/', $references, $matches);
        foreach ($matches[1] as $candidate) {
            if (!str_contains($candidate, '@')) continue;
            [$referenceLocal, $referenceDomain] = explode('@', $candidate, 2);
            // A consistent full reference disambiguates original IDs containing dots.
            if (preg_match('/^' . preg_quote($referenceLocal, '/') . '\.\d{1,2}\.[a-zA-Z0-9]+\.[a-zA-Z0-9]+@'
                . preg_quote($referenceDomain, '/') . '$/D', $messageId) === 1) {
                return self::messageId($candidate);
            }
        }
        $encodedReference = self::commentReference($messageId);
        if ($encodedReference !== null) {
            return $encodedReference;
        }
        if ($matches[1] !== []) {
            return self::messageId($matches[1][0]);
        }
        return trim($references) !== '' ? self::messageId($references) : null;
    }

    private static function commentPoster(string $from): string
    {
        $decodedFrom = @iconv_mime_decode($from, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decodedFrom === false) {
            throw new UnexpectedValueException('Ongeldige auteur-encoding.');
        }
        return trim(explode('<', self::text($decodedFrom), 2)[0], " \t\"");
    }

    public static function binary(string $body): string
    {
        $body = str_replace(["\r", "\n"], '', $body);

        return strtr($body, ['=A' => "\0", '=B' => "\r", '=C' => "\n", '=D' => '=']);
    }

    public static function commentReference(string $messageId): ?string
    {
        [$local, $domain] = explode('@', self::messageId($messageId), 2);
        if (!str_contains($local, '.')) {
            return null;
        }

        // Providers can replace References; Spotnet embeds the spot in the comment ID.
        return self::messageId(explode('.', $local, 2)[0] . '@' . $domain);
    }

    private static function text(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            $text = self::convertToUtf8($text, 'Windows-1252');
        }

        return $text;
    }

    private static function convertToUtf8(string $text, string $charset): string
    {
        $converted = @iconv($charset, 'UTF-8', $text);
        if ($converted === false) {
            throw new UnexpectedValueException('Kan Spotnet tekst niet naar UTF-8 omzetten.');
        }

        return $converted;
    }

    private static function webUrl(string $url): string
    {
        $url = trim($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }

    /** @return list<string> */
    private static function segments(\SimpleXMLElement $element): array
    {
        $segments = [];
        foreach ($element->Segment as $segment) {
            if (count($segments) >= 100) {
                throw new UnexpectedValueException('Te veel Spotnet segmenten.');
            }
            $segments[] = self::messageId((string) $segment);
        }
        return $segments;
    }

    private static function date(string $date): string
    {
        if (trim($date) === '') {
            throw new UnexpectedValueException('Spotnet bericht mist een datum.');
        }
        try {
            return (new DateTimeImmutable($date))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $exception) {
            throw new UnexpectedValueException('Ongeldige datum in Spotnet bericht.', 0, $exception);
        }
    }
}
