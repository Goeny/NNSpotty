<?php

declare(strict_types=1);

final class MusicFixture implements \NnSpotty\Music\Gateway
{
    public const ARTIST = '11111111-1111-4111-8111-111111111111';
    public const GROUP = '22222222-2222-4222-8222-222222222222';
    public const RELEASE = '33333333-3333-4333-8333-333333333333';
    public array $calls = [];
    public bool $fail = false;
    public bool $ambiguous = false;
    public bool $unknownLength = false;
    public int $trackCount = 2;
    public bool $multipleArtists = false;
    public bool $variousArtists = false;

    public function get(string $type, ?string $id, array $query = []): ?array
    {
        $this->calls[] = [$type, $id, $query];
        if ($this->fail) throw new RuntimeException('Synthetic MusicBrainz outage');
        $artist = ['id' => self::ARTIST, 'name' => 'Fixture Artist', 'sort-name' => 'Artist, Fixture'];
        $credit = [['name' => 'Fixture Artist', 'artist' => $artist, 'joinphrase' => '']];
        if ($this->multipleArtists) $credit[] = ['name' => 'Other Artist', 'artist' => ['id' => self::RELEASE], 'joinphrase' => ''];
        if ($this->variousArtists) $credit = [['name' => 'Various Artists', 'artist' => ['id' => '89ad4ac3-39f7-470e-963a-56509c546377']]];
        $group = ['id' => self::GROUP, 'title' => 'Fixture Album', 'primary-type' => 'Album',
            'first-release-date' => '2020-03-01', 'artist-credit' => $credit, 'genres' => [['name' => 'Rock']],
            'disambiguation' => 'Fixture <script>unsafe</script>'];
        $release = ['id' => self::RELEASE, 'title' => 'Fixture Album', 'date' => '2020-03-15', 'country' => 'NL',
            'artist-credit' => $credit, 'release-group' => $group, 'label-info' => [['label' => ['name' => 'Fixture Label']]],
            'cover-art-archive' => ['front' => true], 'media' => [['position' => 1, 'track-count' => 2, 'tracks' => [
                ['number' => '1', 'title' => 'Track One', 'length' => 120000],
                ['number' => '2', 'title' => 'Track <Two>', 'length' => 185000],
            ]]]];
        if ($this->unknownLength) $release['media'][0]['tracks'][0]['length'] = null;
        if ($this->trackCount > 2) {
            $release['media'][0]['track-count'] = $this->trackCount;
            $release['media'][0]['tracks'] = array_fill(0, $this->trackCount, ['number' => '1', 'title' => 'Many tracks', 'length' => 1000]);
        }
        if ($id === null) {
            $row = match ($type) { 'artist' => $artist, 'release-group' => $group, 'release' => $release };
            $rows = [$row];
            if ($this->ambiguous) {
                $other = $row;
                $other['id'] = '44444444-4444-4444-8444-444444444444';
                $rows[] = $other;
            }
            return [match ($type) { 'artist' => 'artists', 'release-group' => 'release-groups', 'release' => 'releases' } => $rows];
        }
        return match ($id) {
            self::ARTIST => $artist + ['type' => 'Group', 'country' => 'NL', 'life-span' => ['begin' => '1995', 'ended' => false]],
            self::GROUP => $group,
            self::RELEASE => $release,
            default => null,
        };
    }
}
