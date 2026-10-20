<?php

declare(strict_types=1);

namespace NnSpotty\Users {
    final class AvatarTestTransport
    {
        public static array|false $records = [['ip' => '93.184.215.14']];
        public static array $options = [];
        public static string $body = '';
        public static int $status = 200;
        public static bool $closed = false;
    }

    function dns_get_record(string $host, int $type): array|false
    {
        return AvatarTestTransport::$records;
    }

    function curl_init(): object
    {
        AvatarTestTransport::$closed = false;
        return new \stdClass();
    }

    function curl_setopt_array(object $handle, array $options): bool
    {
        AvatarTestTransport::$options = $options;
        return true;
    }

    function curl_exec(object $handle): bool
    {
        $write = AvatarTestTransport::$options[CURLOPT_WRITEFUNCTION];
        foreach (str_split(AvatarTestTransport::$body, 16384) as $chunk) {
            if ($write($handle, $chunk) !== strlen($chunk)) return false;
        }
        return true;
    }

    function curl_getinfo(object $handle, int $option): int
    {
        return AvatarTestTransport::$status;
    }

    function curl_close(object $handle): void
    {
        AvatarTestTransport::$closed = true;
    }
}

namespace {
    use NnSpotty\Users\AvatarService;
    use NnSpotty\Users\AvatarTestTransport;

    final class AvatarTestPdo extends PDO
    {
        public array $rows = [];
        public array $queries = [];

        public function __construct()
        {
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->queries[] = $query;
            return new AvatarTestStatement($this, $query);
        }
    }

    final class AvatarTestStatement extends PDOStatement
    {
        private array $values = [];

        public function __construct(private AvatarTestPdo $database, private string $query)
        {
        }

        public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
        {
            $this->values[ltrim((string) $param, ':')] = $value;
            if ($param === ':data') check($type === PDO::PARAM_LOB, 'Avatarbinary wordt als LOB gebonden');
            return true;
        }

        public function execute(?array $params = null): bool
        {
            $this->values = $params ?? $this->values;
            $username = $this->values['username'];
            if (str_starts_with($this->query, 'UPDATE')) {
                $this->database->rows[$username] = [
                    'avatar_data' => $this->values['data'] ?? null,
                    'avatar_mime' => $this->values['mime'] ?? null,
                ];
            }
            return true;
        }

        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return $this->database->rows[$this->values['username']] ?? false;
        }
    }

    $avatarPdo = new AvatarTestPdo();
    $avatar = new AvatarService($avatarPdo);
    $avatarTarget = new ReflectionMethod(AvatarService::class, 'target');
    $avatarHash = new ReflectionMethod(AvatarService::class, 'gravatarUrl');
    $avatarMemoryLimit = new ReflectionMethod(AvatarService::class, 'memoryLimit');
    foreach (['-1' => null, '  -1  ' => null, '33554432' => 33554432.0,
        '32M' => 33554432.0, '32m' => 33554432.0, '32768K' => 33554432.0,
        '1G' => 1073741824.0, '0' => 0.0] as $avatarLimitValue => $avatarExpectedLimit) {
        check($avatarMemoryLimit->invoke($avatar, (string) $avatarLimitValue) === $avatarExpectedLimit,
            'Geheugenlimiet wordt veilig geparseerd: ' . $avatarLimitValue);
    }
    try {
        $avatarMemoryLimit->invoke($avatar, 'unknown');
        check(false, 'Onbekende geheugenlimiet mag niet onbeperkt worden');
    } catch (RuntimeException $exception) {
        check(true, 'Onbekende geheugenlimiet geeft expliciete fout');
    }
    check($avatarHash->invoke($avatar, '  Person@Example.COM ') ===
        'https://www.gravatar.com/avatar/' . hash('sha256', 'person@example.com') . '?d=404&s=256',
        'Gravatar gebruikt SHA256 van genormaliseerde e-mail zonder e-mail in URL');
    rejects(static fn () => $avatar->gravatar('alice', 'not-an-email'), 'Gravatar weigert ongeldige e-mail');
    rejects(static fn () => $avatar->upload('alice', []), 'Ontbrekende upload wordt geweigerd');
    rejects(static fn () => $avatar->upload('alice', ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => __FILE__]),
        'Lokale bestanden zijn geen HTTP-upload');
    rejects(static fn () => $avatar->upload('alice', ['error' => UPLOAD_ERR_OK, 'size' => 2097153, 'tmp_name' => __FILE__]),
        'Upload boven 2 MiB wordt geweigerd');
    rejects(static fn () => $avatar->normalize(''), 'Lege avatar wordt geweigerd');
    rejects(static fn () => $avatar->normalize(str_repeat('x', 2097153)), 'Avatarbytes zijn begrensd op 2 MiB');
    foreach ([
        'http://example.com/a', 'file:///etc/passwd', 'https://user:secret@example.com/a',
        'https://example.com:444/a', 'https://example.com/a#fragment', "https://example.com/\r\nx",
        'https://example.com\\@127.0.0.1/a', 'https://localhost/a', 'https://2130706433/a',
        'https://127.1/a', 'https://127.0.0.1/a', 'https://10.1.2.3/a', 'https://172.16.0.1/a',
        'https://192.168.1.1/a', 'https://169.254.169.254/a', 'https://100.64.0.1/a',
        'https://192.0.2.1/a', 'https://198.18.0.1/a', 'https://224.0.0.1/a',
        'https://[::1]/a', 'https://[fc00::1]/a', 'https://[fe80::1]/a',
        'https://[::ffff:8.8.8.8]/a', 'https://[2002:0808:0808::1]/a',
        'https://[2001:db8::1]/a', 'https://[3fff::1]/a',
    ] as $avatarUnsafeUrl) {
        rejects(static fn () => $avatarTarget->invoke($avatar, $avatarUnsafeUrl), 'Onveilige avatar-URL geweigerd: ' . $avatarUnsafeUrl);
    }
    check($avatarTarget->invoke($avatar, 'https://8.8.8.8:443/a') === ['8.8.8.8', '8.8.8.8'],
        'Publiek IPv4-adres toegestaan');
    check($avatarTarget->invoke($avatar, 'https://[2606:4700:4700::1111]/a')[1] === '2606:4700:4700::1111',
        'Publiek IPv6-adres toegestaan');
    foreach ([
        [['ip' => '93.184.215.14'], ['ip' => '127.0.0.1']],
        [['ip' => '93.184.215.14'], ['ipv6' => '::1']],
    ] as $avatarMixedRecords) {
        AvatarTestTransport::$records = $avatarMixedRecords;
        rejects(static fn () => $avatarTarget->invoke($avatar, 'https://avatars.example.com/a'),
            'Alle DNS-adressen worden gecontroleerd, niet uitsluitend het eerste');
    }
    AvatarTestTransport::$records = false;
    try {
        $avatarTarget->invoke($avatar, 'https://avatars.example.com/a');
        check(false, 'DNS-fout moet expliciet falen');
    } catch (RuntimeException $exception) {
        check(true, 'DNS-fout geeft RuntimeException');
    }
    AvatarTestTransport::$records = [['ip' => '93.184.215.14']];
    check($avatar->get('missing') === null, 'Ontbrekende gebruiker heeft geen avatar');
    $avatarPdo->rows['alice'] = ['avatar_data' => "binary\0data", 'avatar_mime' => 'image/png'];
    check($avatar->get('alice') === ['data' => "binary\0data", 'mime' => 'image/png'], 'Avatar wordt binair uit database gelezen');
    $avatar->remove('alice');
    check($avatar->get('alice') === null && $avatarPdo->rows['alice']['avatar_mime'] === null,
        'Verwijderen wist binary en MIME zonder andere gebruikers te raken');

    $avatarPixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF1sAAAAASUVORK5CYII=', true);
    if (!extension_loaded('gd')) {
        try {
            $avatar->normalize($avatarPixel);
            check(false, 'Ontbrekende GD moet expliciet falen');
        } catch (RuntimeException $exception) {
            check(str_contains($exception->getMessage(), 'GD'), 'Ontbrekende GD geeft expliciete RuntimeException, geen stille skip');
        }
    } else {
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"/>', 'not an image', substr($avatarPixel, 0, 25)] as $avatarInvalid) {
            rejects(static fn () => $avatar->normalize($avatarInvalid), 'SVG, tekst en corrupte rasterdata worden geweigerd');
        }
        $avatarSource = imagecreatetruecolor(600, 300);
        $red = imagecolorallocate($avatarSource, 255, 0, 0);
        $green = imagecolorallocate($avatarSource, 0, 255, 0);
        imagefill($avatarSource, 0, 0, $red);
        imagefilledrectangle($avatarSource, 150, 0, 449, 299, $green);
        ob_start();
        imagepng($avatarSource);
        $avatarInput = ob_get_clean();
        imagedestroy($avatarSource);
        $avatarOriginalLimit = ini_get('memory_limit');
        try {
            $avatarBudget = max(32, (int) ceil(memory_get_usage(true) / 1048576) + 16) . 'M';
            check(ini_set('memory_limit', $avatarBudget) !== false, 'Begrensd geheugenbudget wordt ingesteld voor decodetest');
            // A valid IHDR advertises a large raster without allocating it in the test.
            $avatarLargeHeader = substr_replace($avatarInput, pack('NN', 4096, 4096), 16, 8);
            $avatarLargeHeader = substr_replace($avatarLargeHeader,
                hash('crc32b', substr($avatarLargeHeader, 12, 17), true), 29, 4);
            check(getimagesizefromstring($avatarLargeHeader)[0] === 4096,
                'Gecomprimeerde testavatar kondigt 4096 pixels aan zonder grote allocatie');
            try {
                $avatar->normalize($avatarLargeHeader);
                check(false, 'Te grote decode moet voor GD worden geweigerd');
            } catch (InvalidArgumentException $exception) {
                check(str_contains($exception->getMessage(), 'geheugenbudget'),
                    '4096×4096 wordt voor decoderen expliciet geweigerd bij beperkt geheugen');
            }
            check($avatar->normalize($avatarInput)['mime'] === 'image/png',
                'Kleine avatar blijft werken met beperkt geheugen');
            check(ini_set('memory_limit', '-1') !== false
                && $avatar->normalize($avatarInput)['mime'] === 'image/png',
                'Onbeperkt PHP-geheugen blijft ondersteund');
        } finally {
            ini_set('memory_limit', $avatarOriginalLimit);
        }
        $avatarNormalized = $avatar->normalize($avatarInput . 'secret metadata');
        $avatarInfo = getimagesizefromstring($avatarNormalized['data']);
        check($avatarInfo[0] === 256 && $avatarInfo[1] === 256 && $avatarNormalized['mime'] === 'image/png',
            'Raster wordt gecentreerd naar maximaal 256 vierkant gehercodeerd');
        check(!str_contains($avatarNormalized['data'], 'secret metadata'), 'Metadata en aangehechte payload worden verwijderd');
        $avatarDecoded = imagecreatefromstring($avatarNormalized['data']);
        check((imagecolorat($avatarDecoded, 0, 128) & 0xffffff) === 0x00ff00,
            'Centrale vierkante crop behoudt beeldverhouding');
        imagedestroy($avatarDecoded);
        $avatarTransparent = imagecreatetruecolor(1, 1);
        imagealphablending($avatarTransparent, false);
        imagesavealpha($avatarTransparent, true);
        imagesetpixel($avatarTransparent, 0, 0, imagecolorallocatealpha($avatarTransparent, 0, 0, 0, 127));
        ob_start();
        imagepng($avatarTransparent);
        $avatarTransparentBytes = ob_get_clean();
        imagedestroy($avatarTransparent);
        $avatarDecoded = imagecreatefromstring($avatar->normalize($avatarTransparentBytes)['data']);
        check((imagecolorat($avatarDecoded, 128, 128) >> 24) === 127, 'PNG-transparantie blijft behouden');
        imagedestroy($avatarDecoded);
        foreach (['imagepng', 'imagejpeg', 'imagegif', 'imagewebp'] as $avatarEncoder) {
            if (!function_exists($avatarEncoder)) {
                check(true, $avatarEncoder . ' is niet beschikbaar in deze GD-build; PNG blijft het uitvoerformaat');
                continue;
            }
            $avatarRaster = imagecreatetruecolor(2, 3);
            ob_start();
            $avatarEncoder($avatarRaster);
            $avatarRasterBytes = ob_get_clean();
            imagedestroy($avatarRaster);
            check($avatar->normalize($avatarRasterBytes)['mime'] === 'image/png', $avatarEncoder . ' wordt opnieuw als PNG gecodeerd');
        }
        foreach ([[4097, 1], [1, 4097]] as [$avatarWidth, $avatarHeight]) {
            $avatarWide = imagecreatetruecolor($avatarWidth, $avatarHeight);
            ob_start();
            imagepng($avatarWide);
            $avatarWideBytes = ob_get_clean();
            imagedestroy($avatarWide);
            rejects(static fn () => $avatar->normalize($avatarWideBytes), 'Elke dimensie boven 4096 wordt voor decoderen geweigerd');
        }
    }
    if (extension_loaded('curl')) {
        AvatarTestTransport::$body = str_repeat('x', 2097153);
        foreach ([200, 302, 404] as $avatarStatus) {
            AvatarTestTransport::$status = $avatarStatus;
            if ($avatarStatus !== 200) AvatarTestTransport::$body = $avatarPixel;
            try {
                $avatar->remote('alice', 'https://avatars.example.com/a');
                check(false, 'Oversize, redirect en HTTP-fout moeten falen');
            } catch (RuntimeException $exception) {
                check(AvatarTestTransport::$closed, 'Transportfout sluit verbinding expliciet');
            }
        }
        $avatarOptions = AvatarTestTransport::$options;
        check($avatarOptions[CURLOPT_RESOLVE] === ['avatars.example.com:443:93.184.215.14'],
            'Geselecteerd publiek DNS-adres is gepind met CURLOPT_RESOLVE');
        check($avatarOptions[CURLOPT_PROXY] === '' && $avatarOptions[CURLOPT_NETRC] === CURL_NETRC_IGNORED
            && $avatarOptions[CURLOPT_HTTPAUTH] === CURLAUTH_NONE, 'Geen proxy of impliciete credentials');
        check(!$avatarOptions[CURLOPT_FOLLOWLOCATION] && $avatarOptions[CURLOPT_MAXREDIRS] === 0
            && $avatarOptions[CURLOPT_PROTOCOLS] === CURLPROTO_HTTPS, 'Redirects verboden en transport uitsluitend HTTPS');
        check($avatarOptions[CURLOPT_CONNECTTIMEOUT] === 5 && $avatarOptions[CURLOPT_TIMEOUT] === 15
            && $avatarOptions[CURLOPT_SSL_VERIFYPEER] && $avatarOptions[CURLOPT_SSL_VERIFYHOST] === 2,
            'Verbinding en totale download begrensd met TLS-verificatie');
        check($avatarOptions[CURLOPT_HEADERFUNCTION](new stdClass(), str_repeat('x', 32769)) === 0,
            'Responseheaders worden eveneens begrensd');
        if (extension_loaded('gd')) {
            AvatarTestTransport::$body = $avatarInput;
            AvatarTestTransport::$status = 200;
            $avatar->remote('alice', 'https://avatars.example.com/a');
            check($avatar->get('alice')['mime'] === 'image/png'
                && getimagesizefromstring($avatar->get('alice')['data'])[0] === 256,
                'Remote avatar wordt genormaliseerd en binair per gebruiker opgeslagen');
            $avatar->gravatar('bob', ' Person@Example.COM ');
            check(str_contains(AvatarTestTransport::$options[CURLOPT_URL], hash('sha256', 'person@example.com'))
                && $avatar->get('bob') !== null, 'Gravatar gebruikt dezelfde veilige fetch en databaseopslag');
        }
    }
}
