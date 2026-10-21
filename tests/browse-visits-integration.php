<?php

declare(strict_types=1);

$visitUsers = new \NnSpotty\Users\UserRepository($pdo);
$visitUsers->create('visit-first', 'fixture-visit-password', false);
$visitUsers->create('visit-returning', 'fixture-visit-password', false);
$initialBoundary = $visitUsers->recordBrowseVisit('visit-first');
$storedVisit = $pdo->query("SELECT last_visit_at FROM user_browse_visits WHERE username = 'visit-first'")->fetchColumn();
check(strlen($initialBoundary) === 26 && $initialBoundary <= $storedVisit,
    'Eerste spotbezoek maakt een persoonlijke UTC-grens met microseconden in de database');
$boundary = '2090-01-01 12:00:00.500000';
$pdo->exec("INSERT INTO user_browse_visits VALUES ('visit-returning', '$boundary')");
$previous = $visitUsers->recordBrowseVisit('visit-returning');
check($previous === $boundary && $pdo->query("SELECT last_visit_at FROM user_browse_visits WHERE username = 'visit-returning'")->fetchColumn() === $boundary,
    'Bezoekgrens blijft per gebruiker gescheiden en kan niet door klokterugloop achteruit bewegen');
$template = \NnSpotty\Usenet\SpotnetParser::spot('visit-template@example.invalid', Fixtures::headers('visit-template@example.invalid'));
$template['title'] = 'Visit Counter Fixture';
$template['category'] = 'Beeld';
$template['subcategories'] = ['1z00', '1a09', '1d00'];
$template['posted_at'] = '2000-01-01 00:00:00';
$visitIds = [];
for ($i = 0; $i < 102; $i++) {
    $visitSpot = $template;
    $visitSpot['message_id'] = 'visit-' . $i . '@example.invalid';
    $visitSpot['id'] = hash('sha256', $visitSpot['message_id']);
    if ($i === 101) $visitSpot['subcategories'] = ['1z03', '1a09', '1d00'];
    $spots->saveSpot($visitSpot, 'free.pt');
    $visitIds[] = $visitSpot['id'];
}
$pdo->exec("UPDATE spotnet_spots SET created_at = '2090-01-01 12:00:00.500001' WHERE title = 'Visit Counter Fixture'");
$atBoundary = $pdo->prepare('UPDATE spotnet_spots SET created_at = :date WHERE id = :id');
$atBoundary->execute(['date' => $boundary, 'id' => $visitIds[0]]);
// Direct SQL bypasses the overview read model; mirror the import times there.
$pdo->exec('UPDATE spot_browse b JOIN spotnet_spots s ON s.id = b.spot_id SET b.created_at = s.created_at WHERE s.title = \'Visit Counter Fixture\'');
$newFacets = array_column($spots->facets('Beeld', false, 'Visit Counter Fixture', [], $boundary), 'total', 'code');
check((int) $newFacets['0:z0'] === 100 && $spots->countSpots('Beeld', false, [], 'Visit Counter Fixture') === 101,
    'Filterbadge telt exact 100 nieuwe films terwijl overzicht alle 101 films blijft tonen');
check((int) array_column($spots->facets('Beeld', true, 'Visit Counter Fixture', [], $boundary), 'total', 'code')['0:z3'] === 1
    && !isset($newFacets['0:z3']), 'Nieuwe facetaantallen volgen erotica-zichtbaarheid zonder verborgen aantallen te lekken');
check((int) array_column($spots->facets('Beeld', false, 'Visit Counter Fixture', ['0:a9', '0:d0'], $boundary), 'total', 'code')['0:z0'] === 100
    && $spots->facets('Beeld', false, 'Visit Counter Fixture', ['0:d1'], $boundary) === []
    && $spots->facets('Geluid', false, 'Visit Counter Fixture', [], $boundary) === [],
    'Nieuwtellers respecteren zoekterm, hoofdgroep en alle actieve EN-filters');
check($spots->facets('Beeld', false, 'Visit Counter Fixture', [], '2090-01-01 12:00:00.500002') === [],
    'Eerste bezoek na bestaande imports telt historie niet als nieuw; grens is strikt en microseconde-nauwkeurig');
$createdBefore = $spots->getSpot($visitIds[1])['created_at'];
$refreshSpot = $template;
$refreshSpot['message_id'] = 'visit-1@example.invalid';
$refreshSpot['id'] = $visitIds[1];
$spots->saveSpot($refreshSpot, 'free.pt', true);
check($spots->getSpot($visitIds[1])['created_at'] === $createdBefore
    && (int) array_column($spots->facets('Beeld', false, 'Visit Counter Fixture', [], $boundary), 'total', 'code')['0:z0'] === 100,
    'Refresh verandert importtijd niet en maakt bestaande spots niet opnieuw nieuw');
$sessionBeforeVisitTest = $_SESSION ?? [];
$_SESSION = ['nnspotty_auth_user' => ['username' => 'visit-returning', 'is_admin' => false]];
$visitAuth = new \NnSpotty\Auth\SessionAuth($visitUsers);
check($visitAuth->newSpotsSince() === $boundary, 'Bestaande sessie zonder bezoekgrens wordt bij eerste browse ondersteund');
$pdo->exec("UPDATE user_browse_visits SET last_visit_at = '2091-01-01 00:00:00.000000' WHERE username = 'visit-returning'");
check($visitAuth->newSpotsSince() === $boundary, 'Navigatie en andere sessie wijzigen de vastgelegde huidige sessiegrens niet');
$_SESSION = $sessionBeforeVisitTest;
$deleteVisitSpot = $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?');
foreach ($visitIds as $id) $deleteVisitSpot->execute([$id]);
$visitUsers->delete('visit-first');
$visitUsers->delete('visit-returning');
check((int) $pdo->query("SELECT COUNT(*) FROM user_browse_visits WHERE username LIKE 'visit-%'")->fetchColumn() === 0,
    'Gebruiker verwijderen ruimt persoonlijke bezoekgrenzen via foreign key op');
