<?php

declare(strict_types=1);

namespace NnSpotty\Moderation {
    final class VerificationTestResult
    {
        public static int|false|null $forced = null;
    }

    function openssl_verify(string $data, string $signature, \OpenSSLAsymmetricKey $key, int $algorithm): int|false
    {
        return VerificationTestResult::$forced ?? \openssl_verify($data, $signature, $key, $algorithm);
    }
}

namespace {
    $opensslKey = Fixtures::signingKey();
    $opensslHeaders = Fixtures::signedHeaders('openssl@example.invalid', $opensslKey);
    try {
        foreach ([-1, false] as $opensslFailure) {
            \NnSpotty\Moderation\VerificationTestResult::$forced = $opensslFailure;
            try {
                \NnSpotty\Usenet\SpotnetParser::spot('openssl@example.invalid', $opensslHeaders);
                check(false, 'Onbeschikbare verificatie moet parser expliciet laten falen');
            } catch (\RuntimeException $exception) {
                check(!$exception instanceof \UnexpectedValueException
                    && str_contains($exception->getMessage(), 'RSA/SHA1-verificatie kon niet worden uitgevoerd'),
                    'OpenSSL-uitkomst ' . var_export($opensslFailure, true) . ' is runtimefout, geen ongeldige spot');
            }
        }
        \NnSpotty\Moderation\VerificationTestResult::$forced = 0;
        check(\NnSpotty\Usenet\SpotnetParser::spot('openssl@example.invalid', $opensslHeaders)['signature_state'] === 'invalid',
            'Uitgevoerde maar mislukte verificatie blijft invalid');
    } finally {
        \NnSpotty\Moderation\VerificationTestResult::$forced = null;
    }
    check(\NnSpotty\Usenet\SpotnetParser::spot('openssl@example.invalid', $opensslHeaders)['signature_state'] === 'verified',
        'Echte OpenSSL-verificatie blijft werken na fouttests');
}
