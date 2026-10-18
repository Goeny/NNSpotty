<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use NnSpotty\State\AppStateRepository;
use RuntimeException;

final class SpotImageService
{
    public function __construct(
        private readonly SpotRepository $spots,
        private readonly AppStateRepository $state
    ) {
    }

    /** @param array<string, mixed> $spot
     *  @return array{mime_type: string, image_data: string}
     */
    public function load(array $spot): array
    {
        if (!$this->state->spotContentSettings()['images_enabled']) {
            throw new RuntimeException('Afbeeldingen ophalen en tonen is door de beheerder uitgeschakeld.');
        }
        $cached = $this->spots->image($spot['id']);
        if ($cached !== null) {
            return $cached;
        }
        if ($spot['image_segments'] === []) {
            throw new RuntimeException('Deze spot bevat geen Usenet-afbeelding.');
        }
        $client = NntpClientFactory::connectFromSettings($this->state->getUsenetSettings());
        try {
            $data = '';
            foreach ($spot['image_segments'] as $segment) {
                $data .= SpotnetParser::binary($client->body('<' . SpotnetParser::messageId($segment) . '>'));
                if (strlen($data) > 8 * 1024 * 1024) {
                    throw new RuntimeException('Spotafbeelding is groter dan 8 MiB.');
                }
            }
            $info = @getimagesizefromstring($data);
            if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)
                || $info[0] * $info[1] > 40000000) {
                throw new RuntimeException('Spotafbeelding heeft een ongeldig formaat of afmetingen.');
            }
            $this->spots->saveImage($spot['id'], $info['mime'], $data);
            return ['mime_type' => $info['mime'], 'image_data' => $data];
        } finally {
            $client->disconnect();
        }
    }
}
