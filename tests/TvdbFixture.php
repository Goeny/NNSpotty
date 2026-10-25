<?php

declare(strict_types=1);

final class TvdbFixture implements \NnSpotty\Metadata\Gateway
{
    public array $calls = [];
    public bool $fail = false;
    public array $failPaths = [];
    public array $searchResults = [];
    public bool $missingEpisode = false;
    public ?string $overview = null;
    public array $extraArtwork = [];

    public function get(string $path, array $query = []): ?array
    {
        $this->calls[] = [$path, $query];
        if ($this->fail || in_array($path, $this->failPaths, true)) throw new RuntimeException('Synthetic provider outage');
        if ($path === '/search') return $this->searchResults;
        if ($path === '/search/remoteid/tt1234567') return [['movie' => ['id' => 1]]];
        if ($path === '/artwork/types') return [
            ['id' => 2, 'name' => 'Series Poster'], ['id' => 3, 'name' => 'Series Background'],
            ['id' => 14, 'name' => 'Movie Poster'], ['id' => 15, 'name' => 'Movie Background']];
        if (str_contains($path, '/translations/nld')) return ['name' => 'Voorbeeldtitel', 'overview' => $this->overview ?? 'Nederlandse synopsis <script>niet uitvoeren</script>'];
        if (str_contains($path, '/translations/eng')) return ['name' => 'Fixture Title', 'overview' => 'English overview'];
        if (str_contains($path, '/episodes/official')) return ['episodes' => $this->missingEpisode ? [] : [
            ['id' => 100, 'seriesId' => 10, 'seasonNumber' => 3, 'number' => 2]]];
        if ($path === '/episodes/100/extended') return [
            'id' => 100, 'seriesId' => 10, 'seasonNumber' => 3, 'number' => 2, 'name' => 'Fixture Episode',
            'overview' => 'Episode summary', 'aired' => '2020-02-01', 'runtime' => 42, 'image' => '/banners/episodes/still.jpg'];
        if ($path === '/series/10/extended' || $path === '/movies/1/extended') {
            $movie = $path === '/movies/1/extended';
            return ['id' => $movie ? 1 : 10, 'name' => $movie ? 'Fixture Movie' : 'Fixture Show',
                'nameTranslations' => ['nld', 'eng'], 'overviewTranslations' => ['nld', 'eng'],
                'overview' => 'Original overview', 'runtime' => 116, 'averageRuntime' => 42,
                'firstAired' => '2017-01-01', 'first_release' => ['date' => '2020-01-01'],
                'artworks' => array_merge([['type' => $movie ? 14 : 2, 'image' => '/banners/poster.jpg'],
                    ['type' => $movie ? 15 : 3, 'image' => '/banners/fanart.jpg']], $this->extraArtwork),
                'characters' => [['peopleId' => 55, 'personName' => 'Fixture Actor', 'name' => 'Fixture Role',
                    'peopleType' => 'Actor', 'personImgURL' => '/banners/actor.jpg']],
                'genres' => [['id' => 8, 'name' => 'Drama']],
                'companies' => $movie ? ['studios' => [['id' => 60, 'name' => 'Fixture Studio']]] : [],
                'studios' => [['id' => 60, 'name' => 'Fixture Studio']],
                'remoteIds' => [['id' => 'tt1234567', 'sourceName' => 'IMDB']],
                'score' => 999999];
        }
        return null;
    }

    public static function candidate(string $type = 'movie'): array
    {
        return ['tvdb_id' => $type === 'movie' ? '1' : '10', 'type' => $type,
            'name' => $type === 'movie' ? 'Fixture Movie' : 'Fixture Show', 'year' => $type === 'movie' ? '2020' : '2017'];
    }
}
