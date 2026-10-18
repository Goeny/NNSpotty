<?php

declare(strict_types=1);

require __DIR__ . '/Fixtures.php';

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException($error, $errno);
}
echo substr(strrchr(stream_socket_get_name($server, false), ':'), 1) . PHP_EOL;
flush();
$fail = in_array('--fail', $argv, true);
$failLower = in_array('--fail-lower', $argv, true);

function multiline($socket, string $status, string $data): void
{
    fwrite($socket, $status . "\r\n");
    foreach (explode("\r\n", $data) as $line) {
        fwrite($socket, (str_starts_with($line, '.') ? '.' : '') . $line . "\r\n");
    }
    fwrite($socket, ".\r\n");
}

while ($socket = stream_socket_accept($server, 60)) {
    fwrite($socket, "200 NNSpotty fixture ready\r\n");
    $group = '';
    while (($line = fgets($socket)) !== false) {
        $command = rtrim($line, "\r\n");
        if ($command === 'LIST OVERVIEW.FMT') {
            multiline($socket, '215 Overview format', "Subject:\r\nFrom:\r\nDate:\r\nMessage-ID:\r\nReferences:\r\n:bytes\r\n:lines\r\nXref:full");
        } elseif (str_starts_with($command, 'GROUP ')) {
            $group = substr($command, 6);
            fwrite($socket, $group === 'free.pt' ? "211 806 700 1505 free.pt\r\n" : "211 4 4000 4003 free.usenet\r\n");
        } elseif (preg_match('/^XOVER (\d+)-(\d+)$/', $command, $matches)) {
            if ($fail || ($failLower && $group === 'free.pt' && (int) $matches[1] < 756)) {
                fwrite($socket, "503 Temporary fixture failure\r\n");
                continue;
            }
            $articles = $group === 'free.pt'
                ? [812 => 'spot-one', 913 => 'spot-two', 914 => 'invalid', 915 => 'ordinary', 916 => 'old', 917 => 'expired', 918 => 'truncated', 1400 => 'old']
                : [4001 => 'comment', 4002 => 'spot-one.1.2.3'];
            $rows = [];
            foreach ($articles as $number => $id) {
                if ($number < (int) $matches[1] || $number > (int) $matches[2]) {
                    continue;
                }
                if ($id === 'truncated') {
                    $rows[] = $number . "\tAfgebroken";
                    continue;
                }
                $date = $id === 'old' ? 'Thu, 01 Jan 2009 12:00:00 +0000' : gmdate('r');
                $rows[] = $number . "\tEen individuele testspot\tPoster\t" . $date . "\t<" . $id . "@example.invalid>"
                    . ($id === 'spot-two' ? '' : "\t\t123\t5");
            }
            if ($rows === []) {
                fwrite($socket, "420 No articles selected\r\n");
            } else {
                multiline($socket, '224 Overview follows', implode("\r\n", $rows));
            }
        } elseif (preg_match('/^HEAD <([^>]+)>$/', $command, $matches)) {
            $id = $matches[1];
            if ($id === 'expired@example.invalid') {
                fwrite($socket, "430 No such article\r\n");
            } else {
                $headers = match ($id) {
                    'invalid@example.invalid' => Fixtures::headers($id, '<Spotnet><Posting>'),
                    'ordinary@example.invalid' => 'Subject: Geen spot',
                    'old@example.invalid' => str_replace(gmdate('r'), 'Thu, 01 Jan 2009 12:00:00 +0000', Fixtures::headers($id)),
                    default => Fixtures::headers($id),
                };
                multiline($socket, '221 Header follows', $headers);
            }
        } elseif (preg_match('/^ARTICLE <([^>]+)>$/', $command, $matches)) {
            multiline($socket, '220 Article follows', Fixtures::comment($matches[1] === 'comment@example.invalid' ? '<spot-one@example.invalid>' : '<wrong-provider-reference@example.invalid>'));
        } elseif ($command === 'BODY <image@example.invalid>') {
            $encoded = strtr(Fixtures::image(), ["\0" => '=A', "\r" => '=B', "\n" => '=C', '=' => '=D']);
            multiline($socket, '222 Body follows', rtrim(chunk_split($encoded, 32, "\r\n"), "\r\n"));
        } elseif ($command === 'BODY <nzb@example.invalid>') {
            $encoded = strtr(gzdeflate(Fixtures::nzb()), ["\0" => '=A', "\r" => '=B', "\n" => '=C', '=' => '=D']);
            multiline($socket, '222 Body follows', rtrim(chunk_split($encoded, 32, "\r\n"), "\r\n"));
        } elseif (in_array($command, ['BODY <nzb-first@example.invalid>', 'BODY <nzb-second@example.invalid>'], true)) {
            $encoded = strtr(gzdeflate(Fixtures::nzb()), ["\0" => '=A', "\r" => '=B', "\n" => '=C', '=' => '=D']);
            $split = strpos($encoded, '=') + 1;
            $part = $command === 'BODY <nzb-first@example.invalid>' ? substr($encoded, 0, $split) : substr($encoded, $split);
            multiline($socket, '222 Body follows', $part);
        } elseif (str_starts_with($command, 'BODY <missing-nzb')) {
            fwrite($socket, "430 No such article\r\n");
        } elseif ($command === 'BODY <broken-nzb@example.invalid>') {
            multiline($socket, '222 Body follows', 'not-a-compressed-nzb');
        } elseif ($command === 'QUIT') {
            fwrite($socket, "205 Goodbye\r\n");
            break;
        } else {
            fwrite($socket, "500 Unknown fixture command\r\n");
        }
    }
    fclose($socket);
}
