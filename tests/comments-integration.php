<?php

declare(strict_types=1);

use NnSpotty\Comments\Publisher;
use NnSpotty\Comments\PublicationException;
use NnSpotty\Users\UserRepository;
use NnSpotty\Usenet\SpotnetParser;

$commentSettings = $state->getUsenetSettings();
$commentSpot = SpotnetParser::spot('comment-publish@example.invalid', Fixtures::headers('comment-publish@example.invalid'));
$spots->saveSpot($commentSpot, 'free.pt');
(new UserRepository($pdo))->create('comment-publisher', bin2hex(random_bytes(20)), false);
$commentPublisher = new Publisher($pdo, $state);
$localBeforePublication = (int) $pdo->query('SELECT COUNT(*) FROM post_comments')->fetchColumn();
try {
    foreach ([['accept', 'default'], ['accept', 'inherited'], ['accept', 'custom'],
        ['reject', 'default'], ['deny', 'default'], ['drop', 'default']] as [$postingMode, $connectionMode]) {
        $pdo->exec("UPDATE comment_publications SET created_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE");
        $expectedPostingUsername = $connectionMode === 'custom' ? 'posting-user' : 'reading-user';
        $expectedPostingPassword = $connectionMode === 'custom' ? 'posting-secret' : 'reading-secret';
        $postingProcess = proc_open([PHP_BINARY, __DIR__ . '/comment-server.php', $postingMode, $expectedPostingUsername, $expectedPostingPassword],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $postingPipes);
        if (!is_resource($postingProcess)) throw new RuntimeException('Publicatiefixture kon niet starten.');
        $postingPort = (int) trim(fgets($postingPipes[1]));
        check($postingPort > 0, 'Publicatiefixture luistert: ' . $postingMode);
        $postingSettings = $commentSettings;
        $postingSettings['server'] = '127.0.0.1';
        $postingSettings['port'] = $postingPort;
        $postingSettings['encryption'] = 'none';
        $postingSettings['username'] = 'reading-user';
        $postingSettings['password'] = 'reading-secret';
        $postingSettings['posting_server'] = $connectionMode === 'default' ? '' : '127.0.0.1';
        $postingSettings['posting_port'] = $postingPort;
        $postingSettings['posting_encryption'] = 'none';
        $postingSettings['posting_custom_credentials'] = $connectionMode === 'custom';
        $postingSettings['posting_username'] = 'posting-user';
        $postingSettings['posting_password'] = 'posting-secret';
        if ($connectionMode !== 'default') $postingSettings['port'] = 1;
        $postingSettings['explicit_groups'] = 'spots:free.pt,comments:free.usenet';
        $state->saveUsenetSettings($postingSettings);
        $reloadedPostingSettings = (new \NnSpotty\State\AppStateRepository($pdo))->getUsenetSettings();
        check($reloadedPostingSettings === $postingSettings,
            'Postinginstellingen blijven na herladen behouden: ' . $connectionMode);
        check($reloadedPostingSettings['port'] === ($connectionMode === 'default' ? $postingPort : 1)
            && $reloadedPostingSettings['username'] === 'reading-user',
            'Afzonderlijke postingconfiguratie verandert de leesverbinding niet: ' . $connectionMode);
        $postingToken = bin2hex(random_bytes(32));
        $postedCount = (int) $pdo->query('SELECT COUNT(*) FROM spotnet_comments')->fetchColumn();
        try {
            $commentPublisher->publish('comment-publisher', $commentSpot, "Bedankt caf\u{00E9}!\n.punt\n[img=respekt]", $postingToken);
            check($postingMode === 'accept', 'Alleen 240 geeft publicatiesucces');
        } catch (PublicationException $exception) {
            check($postingMode !== 'accept' && str_contains($exception->getMessage(),
                match ($postingMode) { 'reject' => 'geweigerd', 'deny' => 'posten niet toe', default => 'kan al op Usenet staan' }),
                'Publicatiefout is expliciet: ' . $postingMode);
        } finally {
            $capturedArticle = base64_decode(trim(stream_get_contents($postingPipes[1])), true);
            $postingErrors = stream_get_contents($postingPipes[2]);
            foreach ($postingPipes as $postingPipe) fclose($postingPipe);
            check(proc_close($postingProcess) === 0 && $postingErrors === '', 'Publicatiefixture correct afgesloten: ' . $postingMode);
        }
        $postingRow = $pdo->query("SELECT * FROM comment_publications WHERE request_id = '$postingToken'")->fetch();
        check($postingRow['status'] === match ($postingMode) { 'accept' => 'posted', 'drop' => 'unknown', default => 'failed' },
            'Persistente publicatiestatus correct: ' . $postingMode);
        check((int) $pdo->query('SELECT COUNT(*) FROM spotnet_comments')->fetchColumn() === $postedCount + ($postingMode === 'accept' ? 1 : 0),
            'Alleen bevestigde reactie komt in zichtbare Usenet-cache: ' . $postingMode);
        if ($postingMode !== 'deny') {
            $decodedArticle = SpotnetParser::comment($postingRow['message_id'], $capturedArticle);
            check($decodedArticle['spot_id'] === $commentSpot['id'] && str_contains($decodedArticle['body'], "caf\u{00E9}")
                && str_contains($decodedArticle['body'], ".punt") && str_contains($capturedArticle, 'Newsgroups: free.usenet'),
                'Echte TCP POST heeft geldige framing, encoding en spotverwijzing: ' . $postingMode);
        }
        try {
            $commentPublisher->publish('comment-publisher', $commentSpot, "Bedankt caf\u{00E9}!\n.punt\n[img=respekt]", $postingToken);
            check($postingMode === 'accept', 'Dubbele bevestigde aanvraag slaagt zonder nieuwe providerverbinding');
        } catch (PublicationException $exception) {
            check($postingMode !== 'accept', 'Onbevestigde/mislukte aanvraag wordt niet automatisch opnieuw verzonden');
        }
        if ($postingMode === 'drop') {
            $changedArticle = $decodedArticle;
            $changedArticle['body'] = 'Andere inhoud met hetzelfde ID';
            $spots->saveComment($changedArticle);
            try {
                $commentPublisher->publish('comment-publisher', $commentSpot, "Bedankt caf\u{00E9}!\n.punt\n[img=respekt]", $postingToken);
                check(false, 'Afwijkende opgehaalde inhoud mag poging niet bevestigen');
            } catch (PublicationException) {
                check($pdo->query("SELECT status FROM comment_publications WHERE request_id = '$postingToken'")->fetchColumn() === 'unknown',
                    'Zelfde opgehaalde ID met afwijkende inhoud bevestigt de publicatie niet');
            }
            $pdo->prepare('UPDATE spotnet_comments SET body = :body WHERE id = :id')->execute([
                'body' => $decodedArticle['body'], 'id' => $decodedArticle['id'],
            ]);
            $commentPublisher->publish('comment-publisher', $commentSpot, "Bedankt caf\u{00E9}!\n.punt\n[img=respekt]", $postingToken);
            check($pdo->query("SELECT status FROM comment_publications WHERE request_id = '$postingToken'")->fetchColumn() === 'posted',
                'Later opgehaalde reactie bevestigt onbekende poging zonder opnieuw te publiceren');
        }
    }
    $keyCount = (int) $pdo->query('SELECT COUNT(*) FROM comment_signing_keys')->fetchColumn();
    check($keyCount === 2, 'Persoonlijke en server-RSA-identiteit worden over pogingen hergebruikt');
    check((int) $pdo->query('SELECT COUNT(*) FROM post_comments')->fetchColumn() === $localBeforePublication,
        'Geen publicatiepad maakt lokale reacties aan');
    try {
        $commentPublisher->publish('comment-publisher', $commentSpot, 'Nog een reactie', bin2hex(random_bytes(32)));
        check(false, 'Rate limit vereist');
    } catch (PublicationException $exception) {
        check(str_contains($exception->getMessage(), '30 seconden'), 'Publicatieratelimiet geldt per gebruiker');
    }
    $pdo->exec("UPDATE comment_publications SET created_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE");
    try {
        \NnSpotty\Comments\SigningTestResult::$fail = true;
        $beforeBlocked = (int) $pdo->query('SELECT COUNT(*) FROM comment_publications')->fetchColumn();
        try {
            $commentPublisher->publish('comment-publisher', $commentSpot, 'Geblokkeerde handtekening', bin2hex(random_bytes(32)));
            check(false, 'SHA1-blokkade vereist configuratiefout');
        } catch (PublicationException $exception) {
            check(str_contains($exception->getMessage(), 'serverconfiguratie')
                && (int) $pdo->query('SELECT COUNT(*) FROM comment_publications')->fetchColumn() === $beforeBlocked,
                'SHA1-blokkade stopt voor providerverbinding en verzendclaim; concept niet lokaal gepubliceerd');
        }
    } finally {
        \NnSpotty\Comments\SigningTestResult::$fail = false;
    }
} finally {
    $state->saveUsenetSettings($commentSettings);
    $pdo->prepare('DELETE FROM spotnet_comments WHERE spot_id = :spot')->execute(['spot' => $commentSpot['id']]);
    $pdo->exec("DELETE FROM spotnet_spots WHERE id = '" . $commentSpot['id'] . "'");
    (new UserRepository($pdo))->delete('comment-publisher');
    $pdo->exec("DELETE FROM comment_signing_keys WHERE scope = 'server'");
}
