<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'accept';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) throw new RuntimeException($error, $errno);
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . PHP_EOL;
flush();
$socket = stream_socket_accept($server, 20);
if ($socket === false) exit(1);
stream_set_timeout($socket, 10);
fwrite($socket, "200 NNSpotty posting fixture\r\n");
$article = [];
while (($line = fgets($socket)) !== false) {
    $command = rtrim($line, "\r\n");
    if (str_starts_with($command, 'AUTHINFO USER')) {
        fwrite($socket, "381 Password required\r\n");
    } elseif (str_starts_with($command, 'AUTHINFO PASS')) {
        fwrite($socket, "281 Authenticated\r\n");
    } elseif ($command === 'POST') {
        if ($mode === 'deny') {
            fwrite($socket, "440 Posting not permitted\r\n");
            break;
        }
        fwrite($socket, "340 Send article\r\n");
        while (($line = fgets($socket)) !== false && $line !== ".\r\n") {
            $article[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
        }
        if ($mode !== 'drop') fwrite($socket, $mode === 'reject' ? "441 Posting failed\r\n" : "240 Article received\r\n");
        break;
    } else {
        fwrite($socket, "500 Unknown fixture command\r\n");
    }
}
echo base64_encode(implode('', $article)) . PHP_EOL;
flush();
fclose($socket);
fclose($server);
