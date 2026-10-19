<?php

declare(strict_types=1);

$favoriteRepo = new \NnSpotty\Favorites\Repository($pdo);
$favoriteUsers = new \NnSpotty\Users\UserRepository($pdo);
$favoritePost = static function (string $id, string $value, bool $reader = false) use ($httpPort, &$cookie, &$readerCookie, $token, $readerToken): array {
    return $reader
        ? httpFixture($httpPort, '/favorites', $readerCookie, ['id' => $id, 'favorite' => $value, '_csrf' => $readerToken[1], 'username' => 'http-tester'])
        : httpFixture($httpPort, '/favorites', $cookie, ['id' => $id, 'favorite' => $value, '_csrf' => $token[1], 'username' => 'http-reader']);
};
$anonymousFavoriteCookie = '';
check(httpFixture($httpPort, '/favorites', $anonymousFavoriteCookie, ['id' => $spotId, 'favorite' => '1'])['status'] === 401
    && httpFixture($httpPort, '/favorites', $cookie, ['id' => $spotId, 'favorite' => '1'])['status'] === 403,
    'Favorietenwijziging vereist login en geldige CSRF');
check($favoritePost($spotId, '1')['status'] === 200 && $favoritePost($spotId, '1')['status'] === 200,
    'Toevoegen is idempotent, dubbele request toggelt niet onbedoeld terug');
check($favoriteRepo->annotate([$spots->getSpot($spotId)], 'http-tester')[0]['is_favorite']
    && !$favoriteRepo->annotate([$spots->getSpot($spotId)], 'http-reader')[0]['is_favorite'],
    'Favorieten zijn persoonlijk op sessieusername, vervalst usernameveld genegeerd');
check($favoritePost($spotId, '1', true)['status'] === 200 && $favoritePost($spotId, '0', true)['status'] === 200
    && $favoriteRepo->annotate([$spots->getSpot($spotId)], 'http-tester')[0]['is_favorite'],
    'Gewone gebruiker beheert eigen favorieten zonder die van admin te wijzigen');
$favoriteList = httpFixture($httpPort, '/spots?favorites=1', $cookie);
check($favoriteList['status'] === 200 && str_contains($favoriteList['body'], '>Favorieten</h1>')
    && str_contains($favoriteList['body'], 'data-favorite="' . $spotId . '" aria-pressed="true"')
    && in_array('Cache-Control: private, no-store', $favoriteList['headers'], true),
    'Persoonlijk favorietenoverzicht toont geselecteerde bladwijzer zonder publieke caching');
check(!str_contains(httpFixture($httpPort, '/spots?favorites=1', $readerCookie)['body'], 'data-spot-id="' . $spotId . '"'),
    'Andere gebruiker kan favorieten niet via overzicht inzien');
foreach (['/spots/view?key=', '/spots/preview?key='] as $favoritePath) {
    $favoriteResponse = httpFixture($httpPort, $favoritePath . $spotId, $cookie);
    $favoriteHtml = str_contains($favoritePath, '/preview')
        ? json_decode($favoriteResponse['body'], true, 64, JSON_THROW_ON_ERROR)['html'] : $favoriteResponse['body'];
    check($favoriteResponse['status'] === 200 && str_contains($favoriteHtml, 'data-favorite="' . $spotId . '" aria-pressed="true"'),
        'Geselecteerde persoonlijke favorietstatus beschikbaar in HTTP-detail/preview');
}
foreach ([
    ['id' => '../invalid', 'favorite' => '1'],
    ['id' => [$spotId], 'favorite' => '1'],
    ['id' => $spotId, 'favorite' => ['1']],
    ['id' => $spotId, 'favorite' => 'toggle'],
] as $invalidFavorite) {
    check(httpFixture($httpPort, '/favorites', $cookie, $invalidFavorite + ['_csrf' => $token[1]])['status'] === 400,
        'Ongeldige favorietinvoer wordt expliciet geweigerd');
}
check($favoritePost(str_repeat('0', 64), '1')['status'] === 404, 'Onbekende spot wordt geen favoriet');
check($favoritePost($adultKey, '1', true)['status'] === 404, 'Favorietactie kan verborgen erotica niet ontsluiten');
$favoriteRepo->set('http-reader', $adultKey, true);
check(!str_contains(httpFixture($httpPort, '/spots?favorites=1', $readerCookie)['body'], 'data-spot-id="' . $adultKey . '"'),
    'Reeds opgeslagen favoriet omzeilt latere zichtbaarheid/opt-out niet');
$favoriteRepo->set('http-reader', $adultKey, false);
foreach (['/spots?favorites[]=1', '/spots?favorites=other', '/spots/facets?category=Beeld&favorites[]=1'] as $invalidFavoriteUrl) {
    check(httpFixture($httpPort, $invalidFavoriteUrl, $cookie)['status'] === 400, 'Ongeldige favorietenscope geweigerd');
}
$favoriteBefore = $state->getPreferences('http-tester');
$state->savePreferences('http-tester', ['theme' => 'default', 'items_per_page' => 10, 'show_erotica' => false]);
$favoriteIds = [];
for ($n = 0; $n < 23; $n++) {
    $favoriteMessage = 'favorite-http-' . $n . '@example.invalid';
    $favoriteFixture = \NnSpotty\Usenet\SpotnetParser::spot($favoriteMessage, Fixtures::headers($favoriteMessage));
    $favoriteFixture['title'] = 'Personal favorite fixture ' . $n;
    $spots->saveSpot($favoriteFixture, 'free.pt');
    $favoriteRepo->set('http-tester', $favoriteFixture['id'], true);
    $favoriteIds[] = $favoriteFixture['id'];
}
$favoriteUrl = '/spots?batch=1&favorites=1&category=Beeld&subcategory=0%3Aa1&q=Personal+favorite+fixture';
$favoriteSeen = [];
$favoriteSizes = [];
do {
    $response = httpFixture($httpPort, $favoriteUrl, $cookie);
    $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
    check($response['status'] === 200 && in_array('Cache-Control: private, no-store', $response['headers'], true),
        'Favorietenlazyload blijft authenticated JSON zonder cache');
    preg_match_all('/data-spot-id="([a-f0-9]{64})"/', $payload['html'], $matches);
    $favoriteSeen = [...$favoriteSeen, ...$matches[1]];
    $favoriteSizes[] = $payload['count'];
    $favoriteUrl = $payload['next'];
    if ($favoriteUrl !== null) check(str_contains($favoriteUrl, 'favorites=1') && str_contains($favoriteUrl, 'subcategory=0%3Aa1')
        && str_contains($favoriteUrl, 'q=Personal+favorite+fixture'), 'Favorietenkeyset behoudt gebruiker, scope en alle filters');
} while ($favoriteUrl !== null);
sort($favoriteSeen);
sort($favoriteIds);
check($favoriteSizes === [10, 10, 3] && $favoriteSeen === $favoriteIds, 'Favorietenlazyload laadt uitsluitend begrensde batches, exact eenmaal en volledig');
$favoriteFacet = httpFixture($httpPort, '/spots/facets?favorites=1&category=Beeld&q=Personal+favorite+fixture', $cookie);
$favoriteCounts = json_decode($favoriteFacet['body'], true, 64, JSON_THROW_ON_ERROR)['counts'];
check($favoriteFacet['status'] === 200 && count($favoriteCounts) > 0 && max($favoriteCounts) <= 23,
    'Asynchrone nieuwtellers respecteren favorietenfilter');
$favoriteFacets = $spots->facets('Beeld', false, 'Personal favorite fixture', [], null, 'http-tester');
check($favoriteFacets !== [] && array_unique(array_map('intval', array_column($favoriteFacets, 'total'))) === [23]
    && $spots->facets('Beeld', false, 'Personal favorite fixture', [], null, 'http-reader') === [],
    'Favorietentellers bevatten exact de 23 persoonlijke resultaten, niets voor andere gebruiker');
check($favoritePost($spotId, '0')['status'] === 200 && $favoritePost($spotId, '0')['status'] === 200
    && !$favoriteRepo->annotate([$spots->getSpot($spotId)], 'http-tester')[0]['is_favorite'],
    'Herhaald verwijderen is veilig en behoudt de oorspronkelijke spot');
$favoriteUsers->create('favorite-cascade', 'synthetic-password', false);
$favoriteRepo->set('favorite-cascade', $favoriteIds[0], true);
$favoriteUsers->delete('favorite-cascade');
$favoriteQuery = $pdo->prepare('SELECT COUNT(*) FROM spot_favorites WHERE username = ?');
$favoriteQuery->execute(['favorite-cascade']);
check((int) $favoriteQuery->fetchColumn() === 0, 'Gebruiker verwijderen ruimt persoonlijke favorieten via foreign key op');
$digits = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
    UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$pdo->exec("INSERT INTO spotnet_spots
    (id, message_id, group_name, title, description, poster, category, subcategories, size, posted_at, website, tag,
     image_url, image_segments, nzb_segments, full_xml, created_at, browse_type)
    SELECT SHA2(CONCAT('favorite-scale-', a.n, b.n, c.n, d.n), 256),
     CONCAT('favorite-scale-', a.n, b.n, c.n, d.n, '@example.invalid'), 'free.pt', 'Unrelated scale fixture',
     '', 'Fixture', 'Beeld', '[]', 0, UTC_TIMESTAMP(), '', '', '', '[]', '[]', '', UTC_TIMESTAMP(), 'Films'
    FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c CROSS JOIN $digits d");
$plan = $pdo->query("EXPLAIN SELECT s.id FROM spotnet_spots s
    WHERE s.id IN (SELECT spot_id FROM spot_favorites WHERE username = 'http-tester')
    ORDER BY s.posted_at DESC, s.id DESC LIMIT 11")->fetchAll();
check(count(array_filter($plan, static fn (array $step): bool => $step['table'] === 'spot_favorites' && $step['key'] === 'PRIMARY')) === 1
    && count(array_filter($plan, static fn (array $step): bool => $step['table'] === 's' && $step['type'] === 'eq_ref')) === 1,
    'Favorietenquery gebruikt username-primary-key en exacte spotlookups tussen 10000 niet-favorieten, geen volledige spotscan');
$sparseBatch = $spots->batch(null, 10, false, [], '', null, 'http-tester');
check(count($sparseBatch['spots']) === 10 && $sparseBatch['next'] !== null
    && array_diff(array_column($sparseBatch['spots'], 'id'), $favoriteIds) === [],
    'Schaalfixture levert alleen de tien gevraagde persoonlijke favorieten, niet alle spots');
$pdo->exec("DELETE FROM spotnet_spots WHERE message_id LIKE 'favorite-scale-%@example.invalid'");
$pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (' . implode(',', array_fill(0, count($favoriteIds), '?')) . ')')->execute($favoriteIds);
$favoriteQuery = $pdo->prepare('SELECT COUNT(*) FROM spot_favorites WHERE spot_id IN (' . implode(',', array_fill(0, count($favoriteIds), '?')) . ')');
$favoriteQuery->execute($favoriteIds);
check((int) $favoriteQuery->fetchColumn() === 0, 'Spotverwijdering/retentie ruimt favorieten via foreign key op, favorieten omzeilen retentie niet');
$state->savePreferences('http-tester', $favoriteBefore);
$favoriteScript = httpFixture($httpPort, '/assets/favorites.js', $cookie);
check($favoriteScript['status'] === 200 && str_contains($favoriteScript['body'], "fetch('/favorites'"),
    'Favorietscript wordt geleverd via echte HTTP-server');
