<?php

declare(strict_types=1);

$activitySabSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($activitySabSocket === false) throw new RuntimeException($error, $errno);
$activitySabPort = (int) substr(strrchr(stream_socket_get_name($activitySabSocket, false), ':'), 1);
fclose($activitySabSocket);
$activitySabLog = tempnam(sys_get_temp_dir(), 'nnspotty-activity-sab-');
$activitySabServerLog = tmpfile();
if ($activitySabLog === false || $activitySabServerLog === false) throw new RuntimeException('Kon SABnzbd-testlog niet maken.');
$activitySabPipes = [];
$activitySabServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $activitySabPort, __DIR__ . '/sab-server.php'],
    [['pipe', 'r'], $activitySabServerLog, $activitySabServerLog], $activitySabPipes, dirname(__DIR__),
    array_merge(getenv(), ['NNSPOTTY_SAB_LOG' => $activitySabLog]));
if (!is_resource($activitySabServer)) throw new RuntimeException('Kon SABnzbd-testserver niet starten.');
$activitySabRepo = new \NnSpotty\Downloader\SabRepository($pdo);
$activitySabScope = 'user:activity-http-admin';
try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $activitySabPort, $errno, $error, .1);
        if ($probe !== false) { fclose($probe); $ready = true; break; }
        usleep(100000);
    }
    check($ready, 'SABnzbd-activiteitentestserver is bereikbaar');
    $adminPage = activityHttp($activityPort, '/history', $activityCookies['admin']);
    preg_match('/name="_csrf" value="([^"]+)"/', $adminPage['body'], $adminToken);
    foreach (['sabnzbd' => 201, 'reject' => 502, 'unknown' => 502] as $path => $expected) {
        $activitySabRepo->save($activitySabScope, 'activity-http-admin',
            'http://127.0.0.1:' . $activitySabPort . '/' . $path, str_repeat('a', 32), true, true);
        $prepare = activityHttp($activityPort, '/downloader?action=prepare&id=' . $activityHttpSpot['id'], $activityCookies['admin']);
        $prepared = json_decode($prepare['body'], true, 32, JSON_THROW_ON_ERROR);
        $form = ['_csrf' => $adminToken[1], 'action' => 'send', 'id' => $activityHttpSpot['id'],
            'token' => $prepared['token'], 'revision' => $prepared['revision']];
        $response = activityHttp($activityPort, '/downloader', $activityCookies['admin'], $form);
        $repeat = activityHttp($activityPort, '/downloader', $activityCookies['admin'], $form);
        $history = $activityHttpRepo->history('activity-http-admin', 'download', true, 1)['items'];
        check($response['status'] === $expected && $repeat['status'] === 409
            && count($history) === 1 && (int) $history[0]['action_count'] === 1 && $history[0]['sources'] === 'sabnzbd',
            'Echte SABnzbd-route bewaart alleen bevestigde verzending zonder replaytelling: ' . $path);
    }
} finally {
    proc_terminate($activitySabServer);
    foreach ($activitySabPipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    proc_close($activitySabServer);
    fclose($activitySabServerLog);
    unlink($activitySabLog);
    $activitySabRepo->delete($activitySabScope);
    $pdo->exec("DELETE FROM sabnzbd_downloads WHERE username = 'activity-http-admin'");
}
