<?php

declare(strict_types=1);

namespace NnSpotty\Music;

interface Gateway
{
    public function get(string $type, ?string $id, array $query = []): ?array;
}
