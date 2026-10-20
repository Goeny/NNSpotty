<?php

declare(strict_types=1);

namespace NnSpotty\Users;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class AvatarService
{
    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upload(string $username, array $file): void
    {
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_int($file['size'] ?? null) || $file['size'] < 1 || $file['size'] > self::MAX_BYTES
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Ongeldige avatarupload (maximaal 2 MiB).');
        }
        $data = @file_get_contents($file['tmp_name'], false, null, 0, self::MAX_BYTES + 1);
        if ($data === false) {
            throw new RuntimeException('Avatarupload kon niet worden gelezen.');
        }
        $this->store($username, $this->normalize($data));
    }

    public function remote(string $username, string $url): void
    {
        [$host, $ip] = $this->target($url);
        if (!extension_loaded('curl')) {
            throw new RuntimeException('cURL is vereist om een avatar op te halen.');
        }
        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Avatarverbinding kon niet worden gestart.');
        }
        $data = '';
        $headerBytes = 0;
        try {
            $pinnedIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $configured = curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROXY => '',
                CURLOPT_NETRC => CURL_NETRC_IGNORED,
                CURLOPT_HTTPAUTH => CURLAUTH_NONE,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [$host . ':443:' . $pinnedIp],
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_LOW_SPEED_LIMIT => 128,
                CURLOPT_LOW_SPEED_TIME => 5,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$headerBytes): int {
                    $headerBytes += strlen($header);
                    return $headerBytes <= 32768 ? strlen($header) : 0;
                },
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$data): int {
                    if (strlen($data) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }
                    $data .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!$configured) {
                throw new RuntimeException('Veilige avatarverbinding kon niet worden ingesteld.');
            }
            $result = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result === false || $status !== 200) {
                throw new RuntimeException('Avatar ophalen mislukt (HTTPS 200 vereist; geen redirects; maximaal 2 MiB).');
            }
        } finally {
            curl_close($curl);
        }
        $this->store($username, $this->normalize($data));
    }

    public function gravatar(string $username, string $email): void
    {
        $this->remote($username, $this->gravatarUrl($email));
    }

    private function gravatarUrl(string $email): string
    {
        $email = strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Een geldig e-mailadres is vereist voor Gravatar.');
        }
        return 'https://www.gravatar.com/avatar/' . hash('sha256', $email) . '?d=404&s=256';
    }

    public function remove(string $username): void
    {
        $statement = $this->pdo->prepare('UPDATE app_users SET avatar_data = NULL, avatar_mime = NULL WHERE username = :username');
        $statement->execute(['username' => $username]);
    }

    /** @return array{data: string, mime: string}|null */
    public function get(string $username): ?array
    {
        $statement = $this->pdo->prepare('SELECT avatar_data, avatar_mime FROM app_users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['avatar_data'] === null || $row['avatar_mime'] === null) {
            return null;
        }
        $data = $row['avatar_data'];
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }
        if (!is_string($data)) {
            throw new RuntimeException('Avatar kon niet worden gelezen.');
        }
        return ['data' => $data, 'mime' => (string) $row['avatar_mime']];
    }

    /** @return array{data: string, mime: string} */
    public function normalize(string $data): array
    {
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Avatar moet tussen 1 byte en 2 MiB groot zijn.');
        }
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD is vereist voor veilige avatarverwerking.');
        }
        $info = @getimagesizefromstring($data);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096
            || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Avatar moet een rasterafbeelding van maximaal 4096 × 4096 pixels zijn (JPEG, PNG, GIF of WebP).');
        }
        $limit = $this->memoryLimit((string) ini_get('memory_limit'));
        // GD/codec allocations are not all tracked by PHP; budget for decode copies,
        // resize/encode buffers and allocator overhead before allocating the source.
        $estimatedBytes = $info[0] * $info[1] * 16 + 256 * 256 * 16
            + strlen($data) * 2 + 4 * 1024 * 1024;
        if ($limit !== null && $estimatedBytes > $limit - memory_get_usage(true)) {
            throw new InvalidArgumentException('Avatar is te groot voor het beschikbare geheugenbudget.');
        }
        $source = @imagecreatefromstring($data);
        if ($source === false) {
            throw new InvalidArgumentException('Avatarafbeelding kon niet worden gedecodeerd.');
        }
        $canvas = null;
        try {
            $canvas = imagecreatetruecolor(256, 256);
            if ($canvas === false) {
                throw new RuntimeException('Avatar kon niet worden verkleind.');
            }
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            $side = min($info[0], $info[1]);
            if (!imagecopyresampled($canvas, $source, 0, 0, (int) (($info[0] - $side) / 2),
                (int) (($info[1] - $side) / 2), 256, 256, $side, $side)) {
                throw new RuntimeException('Avatar kon niet worden verkleind.');
            }
            ob_start();
            try {
                $encoded = imagepng($canvas, null, 6);
                $png = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (!$encoded || !is_string($png) || $png === '') {
                throw new RuntimeException('Avatar kon niet worden opgeslagen als PNG.');
            }
            return ['data' => $png, 'mime' => 'image/png'];
        } finally {
            imagedestroy($source);
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
        }
    }

    private function memoryLimit(string $value): ?float
    {
        $value = trim($value);
        if ($value === '-1') {
            return null;
        }
        if (!preg_match('/^([0-9]+)\s*([KMG]?)$/iD', $value, $matches)) {
            throw new RuntimeException('PHP-geheugenlimiet kon niet veilig worden bepaald.');
        }
        $multiplier = match (strtoupper($matches[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
        return (float) $matches[1] * $multiplier;
    }

    /** @param array{data: string, mime: string} $avatar */
    private function store(string $username, array $avatar): void
    {
        $statement = $this->pdo->prepare('UPDATE app_users SET avatar_data = :data, avatar_mime = :mime WHERE username = :username');
        $statement->bindValue(':data', $avatar['data'], PDO::PARAM_LOB);
        $statement->bindValue(':mime', $avatar['mime'], PDO::PARAM_STR);
        $statement->bindValue(':username', $username, PDO::PARAM_STR);
        $statement->execute();
    }

    /** @return array{string, string} */
    private function target(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('Ongeldige avatar-URL.');
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new InvalidArgumentException('Avatar-URL vereist HTTPS op poort 443, zonder inloggegevens of fragment.');
        }
        $host = strtolower($parts['host']);
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$literal];
        } else {
            if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
                || !str_contains($host, '.') || preg_match('/^[0-9.]+$/D', $host)) {
                throw new InvalidArgumentException('Ongeldige avatarhost.');
            }
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if ($records === false || $records === []) {
                throw new RuntimeException('Avatarhost kon niet worden opgezocht.');
            }
            $addresses = [];
            foreach ($records as $record) {
                if (isset($record['ip'])) $addresses[] = $record['ip'];
                if (isset($record['ipv6'])) $addresses[] = $record['ipv6'];
            }
        }
        if ($addresses === []) {
            throw new RuntimeException('Avatarhost heeft geen bruikbaar adres.');
        }
        foreach ($addresses as $address) {
            if (!$this->publicAddress($address)) {
                throw new InvalidArgumentException('Avatarhost mag uitsluitend publieke internetadressen gebruiken.');
            }
        }
        return [$host, $addresses[0]];
    }

    private function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if ($packed === false) return false;
        $blocked = strlen($packed) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3']
            : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        // Limit IPv6 to global unicast, excluding transition and special-purpose ranges.
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20) return false;
        foreach ($blocked as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $bytes = intdiv((int) $bits, 8);
            $remainder = (int) $bits % 8;
            $networkBytes = inet_pton($network);
            if (substr($packed, 0, $bytes) === substr($networkBytes, 0, $bytes)
                && ($remainder === 0 || (ord($packed[$bytes]) & (0xff << (8 - $remainder)))
                    === (ord($networkBytes[$bytes]) & (0xff << (8 - $remainder))))) {
                return false;
            }
        }
        return true;
    }
}
