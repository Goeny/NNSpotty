<?php

declare(strict_types=1);

namespace NnSpotty\Downloader;

interface ConnectionRepository
{
    public const TYPES = ['Films', 'Series', 'Boeken', 'Erotica', 'Afbeeldingen', 'Muziek', 'Livesets', 'Podcasts', 'Luisterboeken', 'Spellen', 'Applicaties', 'Overig'];

    /** @return array<string, mixed> Contains at least url, enabled, configured and mappings. Secrets are never rendered. */
    public function settings(string $scope): array;

    public function delete(string $scope): void;

    /** @param array<string, string> $mapping */
    public function saveMappings(array $mapping): void;

    public function category(array $spot, array $settings): string;

    public function client(array $settings, bool $internalAllowed): DownloadClient;

    /** Secret material that invalidates prepared confirmations when it changes. */
    public function revisionSecret(array $settings): string;
}
