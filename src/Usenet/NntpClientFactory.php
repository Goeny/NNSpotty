<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use Rvdv\Nntp\Client;
use Rvdv\Nntp\Socket\Socket;

final class NntpClientFactory
{
    /**
     * @param array<string, mixed> $settings
     * @return array{server: string, port: int, username: string, password: string, encryption: string}
     */
    public static function postingSettings(array $settings): array
    {
        $server = $settings['posting_server'] ?? '';
        $port = $settings['posting_port'] ?? 563;
        $encryption = $settings['posting_encryption'] ?? 'ssl';
        $customCredentials = $settings['posting_custom_credentials'] ?? false;
        $username = $settings['posting_username'] ?? '';
        $password = $settings['posting_password'] ?? '';
        if (!is_string($server) || !is_int($port) || $port < 1 || $port > 65535
            || !is_string($encryption) || !in_array($encryption, ['none', 'ssl', 'tls'], true)
            || !is_bool($customCredentials) || !is_string($username) || !is_string($password)) {
            throw new \InvalidArgumentException('Ongeldige instellingen voor de postingserver.');
        }
        $server = trim($server);
        $username = trim($username);
        if ($server !== '' && preg_match('/[\s\x00-\x1f\x7f\/\\\\]/', $server)) {
            throw new \InvalidArgumentException('Vul alleen de hostnaam of het IP-adres van de postingserver in.');
        }
        if ($server !== '' && $customCredentials && ($username === '' || $password === ''
            || preg_match('/[\r\n\x00]/', $username . $password))) {
            throw new \InvalidArgumentException('Vul een geldige gebruikersnaam en wachtwoord voor de postingserver in.');
        }
        return [
            'server' => $server !== '' ? $server : $settings['server'],
            'port' => $server !== '' ? $port : $settings['port'],
            'encryption' => $server !== '' ? $encryption : $settings['encryption'],
            'username' => $server !== '' && $customCredentials ? $username : $settings['username'],
            'password' => $server !== '' && $customCredentials ? $password : $settings['password'],
        ];
    }

    /**
     * @param array{server: string, port: int, username: string, password: string, encryption: string} $settings
     */
    public static function createFromSettings(array $settings): Client
    {
        $host = trim((string) ($settings['server'] ?? ''));
        $port = (int) ($settings['port'] ?? 119);
        $timeout = 10.0;
        $encryption = strtolower((string) ($settings['encryption'] ?? ''));
        $isSecure = in_array($encryption, ['tls', 'ssl'], true);

        if ($host === '') {
            throw new \RuntimeException('Usenet server ontbreekt in admin instellingen.');
        }

        $connection = new PipelineConnection($host, $port, $isSecure, new Socket($timeout));
        return new Client($connection);
    }

    /**
     * @param array{server: string, port: int, username: string, password: string, encryption: string} $settings
     */
    public static function connectFromSettings(array $settings): Client
    {
        $client = self::createFromSettings($settings);
        $host = trim((string) ($settings['server'] ?? ''));
        $port = (int) ($settings['port'] ?? 0);
        $encryption = strtolower((string) ($settings['encryption'] ?? 'none'));

        $previousErrorHandler = set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException($message, $severity);
        });

        try {
            $client->connect();

            $username = trim((string) ($settings['username'] ?? ''));
            $password = (string) ($settings['password'] ?? '');
            if ($username !== '' && $password !== '') {
                $client->authenticate($username, $password);
            }
        } catch (\Throwable $throwable) {
            $hint = self::connectionHint($encryption, $port);
            throw new \RuntimeException(
                'NNTP connectie mislukt voor ' . $host . ':' . $port . ' (encryptie=' . $encryption . '). ' . $hint . ' Details: ' . $throwable->getMessage(),
                0,
                $throwable
            );
        } finally {
            restore_error_handler();
        }

        return $client;
    }

    private static function connectionHint(string $encryption, int $port): string
    {
        if ($encryption === 'ssl' && $port === 119) {
            return 'Waarschijnlijk SSL op poort 119. Gebruik meestal poort 563 voor SSL of zet encryptie op none/tls.';
        }

        if (($encryption === 'none' || $encryption === '') && $port === 563) {
            return 'Waarschijnlijk poort 563 zonder SSL/TLS. Zet encryptie op ssl.';
        }

        if ($encryption === 'tls' && $port === 563) {
            return 'Veel providers gebruiken op poort 563 SSL i.p.v. TLS.';
        }

        return 'Controleer server, poort en encryptie-combinatie in admin instellingen.';
    }
}
