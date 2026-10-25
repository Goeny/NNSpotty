<?php

declare(strict_types=1);

use NnSpotty\Activity\Repository as ActivityRepository;
use NnSpotty\Activity\View as ActivityView;

$activityUsers = new \NnSpotty\Users\UserRepository($pdo);
$activityUsers->create('activity-one', 'synthetic-activity-password', false);
$activityUsers->create('activity-two', 'synthetic-activity-password', false);
$activity = new ActivityRepository($pdo);
$activitySpots = new \NnSpotty\Usenet\SpotRepository($pdo);
$activitySpot = \NnSpotty\Usenet\SpotnetParser::spot('activity@example.invalid', Fixtures::headers('activity@example.invalid'));
$activitySpot['title'] = '<script>history()</script> & film';
$activitySpots->saveSpot($activitySpot, 'free.pt');
$activitySpot = $activitySpots->getSpot($activitySpot['id']);
$activityAdult = \NnSpotty\Usenet\SpotnetParser::spot('activity-adult@example.invalid',
    Fixtures::headers('activity-adult@example.invalid', str_replace('<Sub>1a01</Sub>', '<Sub>1d75</Sub>', Fixtures::xml())));
$activitySpots->saveSpot($activityAdult, 'free.pt');
$activityAdult = $activitySpots->getSpot($activityAdult['id']);
$emptyStats = $activity->statistics(30, true);
check(str_contains(ActivityView::statistics($emptyStats), 'Nog geen activiteit in deze periode.')
    && $emptyStats['totals'] === ['view' => 0, 'download' => 0], 'Lege statistieken tonen nulwaarden en expliciete lege grafieken');
$backfillSab = new \NnSpotty\Downloader\SabRepository($pdo);
$backfillAttempt = $backfillSab->begin('user:activity-two', 'activity-two', $activitySpot, '', bin2hex(random_bytes(16)));
$pdo->prepare("UPDATE sabnzbd_downloads SET status = 'sent' WHERE id = ?")->execute([$backfillAttempt['id']]);
$pdo->exec("DELETE FROM migrations WHERE name = '20261025_0025_activity'");
(new \NnSpotty\Database\Migrator($pdo))->migrate();
(new \NnSpotty\Database\Migrator($pdo))->migrate();
check((int) $activity->history('activity-two', 'download', true, 1)['items'][0]['action_count'] === 1
    && $activity->statistics(30, true)['totals']['download'] === 1,
    'Migratie neemt bestaande bevestigde SABnzbd-verzending eenmaal over in historie en dagtotalen');
$beforeActivity = $activity->statistics(30, true)['totals'];
try {
    check(!$activityUsers->findByUsername('activity-one')['is_admin']
        && !\NnSpotty\Users\Permissions::allows($activityUsers->findByUsername('activity-one'), 'statistics.view'),
        'Statistieken zijn standaard niet toegestaan aan gewone gebruikers');
    $activity->record('activity-one', $activitySpot, 'detail');
    $activity->record('activity-one', $activitySpot, 'detail');
    $activity->record('activity-two', $activitySpot, 'detail');
    $history = $activity->history('activity-one', 'view', false, 1);
    check(count($history['items']) === 1 && (int) $history['items'][0]['action_count'] === 2
        && $history['items'][0]['last_at'] >= $history['items'][0]['first_at'], 'Herhaald bekijken bundelt per gebruiker/spot met aantal en datums');
    check((int) $activity->history('activity-two', 'view', false, 1)['items'][0]['action_count'] === 1,
        'Historie van andere gebruiker is strikt gescheiden');
    foreach (['nzb', 'newznab'] as $source) $activity->record('activity-one', $activitySpot, $source);
    $activitySab = new \NnSpotty\Downloader\SabRepository($pdo);
    $attempt = $activitySab->begin('user:activity-one', 'activity-one', $activitySpot, '', bin2hex(random_bytes(16)));
    $activitySab->finish((int) $attempt['id'], 'sent', 'fixture');
    $activitySab->finish((int) $attempt['id'], 'sent', 'fixture');
    $failedAttempt = $activitySab->begin('user:activity-one', 'activity-one', $activitySpot, '', bin2hex(random_bytes(16)));
    $activitySab->finish((int) $failedAttempt['id'], 'failed');
    $unknownAttempt = $activitySab->begin('user:activity-one', 'activity-one', $activitySpot, '', bin2hex(random_bytes(16)));
    $activitySab->finish((int) $unknownAttempt['id'], 'unknown');
    $downloadHistory = $activity->history('activity-one', 'download', false, 1)['items'];
    check(count($downloadHistory) === 1 && (int) $downloadHistory[0]['action_count'] === 3
        && $downloadHistory[0]['sources'] === 'newznab,nzb,sabnzbd', 'Downloadbronnen bundelen; SAB-confirmatie telt eenmaal, failed/unknown nooit');
    $stats = $activity->statistics(30, true);
    check($stats['totals']['view'] === $beforeActivity['view'] + 3 && $stats['totals']['download'] === $beforeActivity['download'] + 3,
        'Statistieken tellen elke herhaalde actie, over gebruikers en bronnen');
    check(count($stats['daily']) === 30 && isset($stats['daily'][gmdate('Y-m-d')])
        && count($activity->statistics(7, false)['daily']) === 7 && count($activity->statistics(90, false)['daily']) === 90,
        'Grafieken vullen exact de gekozen UTC-dagen, inclusief vandaag en nuldagen');
    $activity->record('activity-one', $activityAdult, 'detail');
    $activity->record('activity-one', $activityAdult, 'nzb');
    check(count($activity->history('activity-one', 'view', false, 1)['items']) === 1
        && count($activity->history('activity-one', 'view', true, 1)['items']) === 2,
        'Historie respecteert erotiekzichtbaarheid');
    check($activity->statistics(30, true)['totals']['view'] === $activity->statistics(30, false)['totals']['view'] + 1,
        'Verborgen erotiek telt niet mee in zichtbare statistieken');
    $historyHtml = ActivityView::history($history, 'view', 1);
    check(str_contains($historyHtml, '&lt;script&gt;history()&lt;/script&gt; &amp; film')
        && !str_contains($historyHtml, '<script>history()'), 'Historietitels worden geescaped');
    $downloadHtml = ActivityView::history(['items' => $downloadHistory, 'more' => false], 'download', 1);
    check(str_contains($downloadHtml, 'Newznab API, NZB-bestand, SABnzbd'), 'Gedownloadtab toont alle gebruikte bronnen');
    check(str_contains(ActivityView::statistics($stats), '<svg') && str_contains(ActivityView::statistics($stats), 'Exacte aantallen per dag'),
        'Grafieken hebben lokale SVG en toegankelijke exacte dagwaarden');
    rejects(static fn () => $activity->history('activity-one', 'invalid', false, 1), 'Onbekende historietab geweigerd');
    rejects(static fn () => $activity->history('activity-one', 'view', false, 0), 'Ongeldige historiepaginering geweigerd');
    rejects(static fn () => $activity->statistics(100000, false), 'Onbegrensde statistiekperiode geweigerd');
    rejects(static fn () => $activity->record('activity-one', $activitySpot, 'invalid'), 'Onbekende actiebron geweigerd');
    $pdo->beginTransaction();
    $activity->record('activity-one', $activitySpot, 'nzb');
    $pdo->rollBack();
    check((int) $activity->history('activity-one', 'download', false, 1)['items'][0]['action_count'] === 3,
        'Geneste opslag respecteert de transactie van de aanroeper');
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id = ?')->execute([$activitySpot['id']]);
    $removed = $activity->history('activity-one', 'view', false, 1);
    check(count($removed['items']) === 1 && !(bool) $removed['items'][0]['available']
        && str_contains(ActivityView::history($removed, 'view', 1), 'Niet meer beschikbaar'),
        'Spotretentie bewaart historie zonder dode detailkoppeling');
    $activityUsers->delete('activity-one');
    check($activity->history('activity-one', 'view', true, 1)['items'] === []
        && $activity->statistics(30, true)['totals']['view'] === $beforeActivity['view'] + 4,
        'Accountverwijdering wist persoonlijke historie en bewaart anonieme dagtotalen');
    for ($i = 0; $i < 31; $i++) {
        $pageSpot = $activitySpot;
        $pageSpot['id'] = hash('sha256', 'activity-page-' . $i);
        $activity->record('activity-two', $pageSpot, 'detail');
    }
    $pdo->exec("UPDATE activity_history SET last_at = '2026-01-01 00:00:00' WHERE username = 'activity-two'");
    $firstPage = $activity->history('activity-two', 'view', true, 1);
    $secondPage = $activity->history('activity-two', 'view', true, 2);
    check(count($firstPage['items']) === ActivityRepository::PAGE_SIZE && $firstPage['more']
        && count($secondPage['items']) === 2 && !$secondPage['more']
        && count(array_unique(array_column([...$firstPage['items'], ...$secondPage['items']], 'spot_id'))) === 32,
        'Historiepaginering levert begrensde paginas zonder dubbelen bij gelijke tijden');
    $beforeFailure = $activity->statistics(30, true)['totals'];
    try {
        $activity->record('missing-activity-user', $activitySpot, 'detail');
        check(false, 'Ontbrekende gebruiker moet opslag blokkeren');
    } catch (PDOException) {
        check($activity->statistics(30, true)['totals'] === $beforeFailure && !$pdo->inTransaction(),
            'Opslagfout wordt doorgegeven en laat geen gedeeltelijke statistiektelling achter');
    }
} finally {
    $pdo->exec("DELETE FROM sabnzbd_downloads WHERE username IN ('activity-one', 'activity-two')");
    $pdo->exec("DELETE FROM app_users WHERE username IN ('activity-one', 'activity-two')");
    $pdo->prepare('DELETE FROM spotnet_spots WHERE id IN (?, ?)')->execute([$activitySpot['id'], $activityAdult['id']]);
    $activitySpots->refreshBrowseCounts();
}
