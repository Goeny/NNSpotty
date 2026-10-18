<?php

declare(strict_types=1);

namespace NnSpotty\Metadata;

interface Gateway
{
    /** @return array<mixed>|null */
    public function get(string $path, array $query = []): ?array;
}
