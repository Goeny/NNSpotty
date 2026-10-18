<?php

declare(strict_types=1);

namespace NnSpotty\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body,
        private readonly array $headers = []
    ) {
    }

    public static function html(string $body, int $statusCode = 200): self
    {
        return new self($statusCode, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $statusCode = 200): self
    {
        return new self(
            $statusCode,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function notFound(): self
    {
        return self::json(['error' => 'Not Found'], 404);
    }

    /** @param array<string, mixed> $payload */
    public static function privateJson(array $payload, int $statusCode = 200): self
    {
        return new self($statusCode, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function withoutBody(): self
    {
        return new self($this->statusCode, '', $this->headers);
    }

    public static function redirect(string $location): self
    {
        return new self(302, '', ['Location' => $location]);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
