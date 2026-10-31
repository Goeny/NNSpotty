<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

interface DownloadClient
{
    /** @return list<string> */
    public function categories(): array;

    /**
     * Normalized, secret-free queue snapshot.
     *
     * @return array{status: string, speed: float, total: int, slots: list<array{title: string, status: string, percentage: float, category: string, eta: string}>}
     */
    public function queue(int $offset, int $limit): array;

    /** Uploads the NZB and returns the remote download ID; throws ClientRejected on a definitive refusal. */
    public function add(string $title, string $nzb, string $category): string;
}
