<?php

declare(strict_types=1);

namespace NnSpotty\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query,
        private readonly array $body,
        private readonly string $clientIp,
        private readonly string $accept = ''
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public static function fromGlobals(array $server, array $query, array $post): self
    {
        return new self(
            method: strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            uri: (string) ($server['REQUEST_URI'] ?? '/'),
            query: $query,
            body: $post,
            clientIp: (string) ($server['REMOTE_ADDR'] ?? 'unknown'),
            accept: (string) ($server['HTTP_ACCEPT'] ?? '')
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $value = $this->body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function expectsJson(): bool
    {
        return str_contains(strtolower($this->accept), 'application/json');
    }

    public function queryScalar(string $key): ?string
    {
        return $this->scalar($this->query, $key);
    }

    public function inputScalar(string $key): ?string
    {
        return $this->scalar($this->body, $key);
    }

    /** @param array<string, mixed> $values */
    private function scalar(array $values, string $key): ?string
    {
        if (isset($values[$key]) && !is_string($values[$key])) {
            throw new \InvalidArgumentException('Ongeldige tekstwaarde voor ' . $key . '.');
        }
        return ($values[$key] ?? '') !== '' ? $values[$key] : null;
    }
}
