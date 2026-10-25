<?php

declare(strict_types=1);

namespace NnSpotty\Config;

final class AppConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $environment,
        public readonly bool $debug,
        public readonly string $url,
        public readonly string $version,
        public readonly string $codename
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            name: envValue('APP_NAME', 'NNSpotty') ?? 'NNSpotty',
            environment: envValue('APP_ENV', 'development') ?? 'development',
            debug: filter_var(envValue('APP_DEBUG', 'true') ?? 'true', FILTER_VALIDATE_BOOL),
            url: envValue('APP_URL', 'http://localhost:8080') ?? 'http://localhost:8080',
            version: Release::VERSION,
            codename: Release::CODENAME
        );
    }
}
