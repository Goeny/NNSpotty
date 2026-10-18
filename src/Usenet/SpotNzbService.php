<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use NnSpotty\Http\Response;
use NnSpotty\State\AppStateRepository;
use PDO;
use RuntimeException;

final class SpotNzbService
{
    private const MAX_BYTES = 16 * 1024 * 1024 - 1;

    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    /** @param array<string, mixed> $spot */
    public function load(array $spot): string
    {
        if ($spot['nzb_segments'] === []) {
            throw new RuntimeException('Deze spot bevat geen NZB-verwijzing.');
        }
        $select = $this->pdo->prepare('SELECT nzb_data FROM spotnet_nzbs WHERE spot_id = :id');
        $select->execute(['id' => $spot['id']]);
        $cached = $select->fetchColumn();
        if (is_string($cached)) {
            return $cached;
        }
        $client = NntpClientFactory::connectFromSettings($this->state->getUsenetSettings());
        try {
            $encoded = '';
            foreach ($spot['nzb_segments'] as $segment) {
                $encoded .= $client->body('<' . SpotnetParser::messageId($segment) . '>');
                if (strlen($encoded) > self::MAX_BYTES) {
                    throw new RuntimeException('NZB-segmenten zijn groter dan 16 MiB.');
                }
            }
            $nzb = self::decode($encoded);
            $save = $this->pdo->prepare('INSERT INTO spotnet_nzbs (spot_id, nzb_data) VALUES (:id, :data)
                ON DUPLICATE KEY UPDATE nzb_data = VALUES(nzb_data)');
            $save->execute(['id' => $spot['id'], 'data' => $nzb]);
            return $nzb;
        } finally {
            $client->disconnect();
        }
    }

    public static function decode(string $encoded): string
    {
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new RuntimeException('NZB-segmenten zijn groter dan 16 MiB.');
        }
        $data = @gzinflate(SpotnetParser::binary($encoded), self::MAX_BYTES);
        if ($data === false || $data === '') {
            throw new RuntimeException('NZB decompressie mislukt of limiet van 16 MiB overschreden.');
        }
        return self::validate($data);
    }

    public static function validate(string $data): string
    {
        // Strip only the conventional external declaration; never load its DTD.
        $data = preg_replace('~<!DOCTYPE\s+nzb\s+PUBLIC\s+"-//newzBin//DTD NZB 1\.[01]//EN"\s+"https?://www\.newzbin\.com/DTD/nzb/nzb-1\.[01]\.dtd"\s*>~i', '', $data);
        if ($data === null) {
            throw new RuntimeException('NZB-declaratie kon niet worden verwerkt.');
        }
        if (strlen($data) > self::MAX_BYTES || preg_match('/<!DOCTYPE|<!ENTITY/i', $data)) {
            throw new RuntimeException('NZB is te groot of bevat verboden XML-declaraties.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($data, \SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false || $xml->getName() !== 'nzb') {
                throw new RuntimeException('NZB bevat geen geldig NZB XML-document.');
            }
            $root = dom_import_simplexml($xml);
            if ($root === false || !in_array($root->namespaceURI, [null, '', 'http://www.newzbin.com/DTD/2003/nzb'], true)
                || $xml->xpath('//*[namespace-uri() != namespace-uri(/*)]') !== []) {
                throw new RuntimeException('Onbekende NZB namespace.');
            }
            $files = $xml->xpath('/*[local-name()="nzb"]/*[local-name()="file"]');
            if ($files === false || $files === [] || count($files) > 100000) {
                throw new RuntimeException('NZB bevat geen bestanden of te veel bestanden.');
            }
            foreach ($files as $file) {
                $segments = $file->xpath('./*[local-name()="segments"]/*[local-name()="segment"]');
                $groups = $file->xpath('./*[local-name()="groups"]/*[local-name()="group"]');
                if (trim((string) $file['subject']) === '' || !$segments || !$groups) {
                    throw new RuntimeException('NZB-bestand mist onderwerp, groepen of segmenten.');
                }
                foreach ($segments as $segment) {
                    SpotnetParser::messageId((string) $segment);
                    if (!ctype_digit((string) $segment['bytes']) || !ctype_digit((string) $segment['number'])
                        || (int) $segment['number'] < 1) {
                        throw new RuntimeException('NZB bevat ongeldige segmentattributen.');
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $data;
    }

    public static function download(string $data, string $id): Response
    {
        return new Response(200, $data, [
            'Content-Type' => 'application/x-nzb',
            'Content-Disposition' => 'attachment; filename="nnspotty-' . $id . '.nzb"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
