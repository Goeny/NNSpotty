<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use Rvdv\Nntp\Client;
use Rvdv\Nntp\Socket\Socket;

final class NntpClientFactory
{
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
