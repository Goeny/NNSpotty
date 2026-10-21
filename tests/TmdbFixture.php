<?php

declare(strict_types=1);

final class TmdbFixture implements \NnSpotty\Metadata\Gateway
{
    public array $calls = [];
    public bool $fail = false;
    public bool $moviePoster = true;

    public function get(string $path, array $query = []): ?array
    {
        $this->calls[] = [$path, $query];
        if ($this->fail) throw new RuntimeException('Synthetic TMDB outage');
        if ($path === '/search/movie') return ['results' => [
            ['id' => 603, 'title' => 'Fixture Movie', 'original_title' => 'Fixture Movie', 'release_date' => '2020-05-01'],
            ['id' => 604, 'title' => 'Fixture Movie', 'original_title' => 'Fixture Movie', 'release_date' => '1999-05-01']]];
        if ($path === '/search/tv') return ['results' => [['id' => 700, 'name' => 'Fixture Show', 'original_name' => 'Fixture Show', 'first_air_date' => '2017-01-01']]];
        if ($path === '/find/tt1234567') return ['movie_results' => [['id' => 603]], 'tv_results' => []];
        if ($path === '/find/10') return ['movie_results' => [], 'tv_results' => [['id' => 700]]];
        if ($path === '/movie/603') return [
            'id' => 603, 'title' => 'TMDB Fixture Film', 'overview' => ($query['language'] ?? '') === 'nl-NL' ? '' : 'TMDB English overview',
            'release_date' => '2020-05-01', 'runtime' => 117, 'genres' => [['id' => 1, 'name' => 'Actie']],
            'poster_path' => $this->moviePoster ? '/tmdb-poster.jpg' : null, 'backdrop_path' => '/tmdb-backdrop.jpg',
            'vote_average' => 7.84, 'vote_count' => 1234,
            'credits' => ['cast' => [['id' => 9, 'name' => 'TMDB Actor', 'character' => 'Hero', 'profile_path' => '/actor.jpg']],
                'crew' => [['id' => 10, 'name' => 'TMDB Director', 'job' => 'Director'], ['id' => 11, 'name' => 'Caterer', 'job' => 'Catering']]],
            'videos' => ['results' => [
                ['site' => 'YouTube', 'key' => 'teaserKEY01', 'type' => 'Teaser', 'official' => true, 'iso_639_1' => 'en', 'name' => 'Teaser'],
                ['site' => 'YouTube', 'key' => 'trailerKEY1', 'type' => 'Trailer', 'official' => true, 'iso_639_1' => 'en', 'name' => 'Official Trailer <b>'],
                ['site' => 'YouTube', 'key' => 'bad"key', 'type' => 'Trailer', 'official' => true, 'iso_639_1' => 'en', 'name' => 'Broken'],
                ['site' => 'Dailymotion', 'key' => 'x123456', 'type' => 'Trailer', 'official' => true, 'iso_639_1' => 'en', 'name' => 'Other site']]],
            'release_dates' => ['results' => [['iso_3166_1' => 'US', 'release_dates' => [['certification' => 'PG-13']]],
                ['iso_3166_1' => 'NL', 'release_dates' => [['certification' => ''], ['certification' => '12']]]]],
            'external_ids' => ['imdb_id' => 'tt1234567'],
            'production_companies' => [['id' => 77, 'name' => 'TMDB Studio']]];
        if ($path === '/tv/700') return [
            'id' => 700, 'name' => 'TMDB Fixture Show', 'overview' => 'TMDB show overview', 'first_air_date' => '2017-01-01',
            'episode_run_time' => [44], 'genres' => [['id' => 2, 'name' => 'Drama']], 'poster_path' => '/show.jpg', 'backdrop_path' => null,
            'vote_average' => 8.1, 'vote_count' => 321, 'credits' => ['cast' => []], 'created_by' => [['id' => 12, 'name' => 'Show Creator']],
            'videos' => ['results' => [['site' => 'Vimeo', 'key' => '123456', 'type' => 'Trailer', 'official' => false, 'iso_639_1' => 'nl', 'name' => 'Seizoenstrailer']]],
            'content_ratings' => ['results' => [['iso_3166_1' => 'NL', 'rating' => '16']]],
            'external_ids' => ['imdb_id' => 'tt7654321', 'tvdb_id' => 10], 'networks' => [['id' => 88, 'name' => 'TMDB Network']]];
        if ($path === '/tv/700/season/3/episode/2') return [
            'id' => 7002, 'season_number' => 3, 'episode_number' => 2, 'name' => 'TMDB Episode', 'overview' => 'TMDB episode overview',
            'air_date' => '2020-02-01', 'runtime' => 43, 'still_path' => '/still.jpg', 'vote_average' => 9.0, 'vote_count' => 12,
            'credits' => ['guest_stars' => [['id' => 13, 'name' => 'Guest Star', 'character' => 'Visitor']], 'crew' => []]];
        return null;
    }
}
