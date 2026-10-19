<?php

declare(strict_types=1);

namespace NnSpotty\Music;

interface FanartGateway
{
    public function get(string $artistId): ?array;
}
