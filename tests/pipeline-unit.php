<?php

declare(strict_types=1);

use NnSpotty\Usenet\PipelineConnection;
use Rvdv\Nntp\Exception\RuntimeException as PipelineException;
use Rvdv\Nntp\Socket\SocketInterface;

final class PipelineSocketFixture implements SocketInterface
{
    public array $events = [];
    public string $written = '';
    public int $position = 0;
    public int $writeLimit = PHP_INT_MAX;
    public bool $failWrite = false;

    public function __construct(public string $input) {}
    public function connect(string $address): self { return $this; }
    public function disconnect(): self { return $this; }
    public function enableCrypto(bool $enable, int $cryptoType = STREAM_CRYPTO_METHOD_TLS_CLIENT): self { return $this; }
    public function eof(): bool { return $this->position >= strlen($this->input); }
    public function read(int $length): string
    {
        $data = substr($this->input, $this->position, $length);
        $this->position += strlen($data);
        return $data;
    }
    public function gets(?int $length = null): string
    {
        $this->events[] = 'read';
        $end = strpos($this->input, "\n", $this->position);
        return $this->read(min(($length ?? 8192) - 1, $end === false ? 8191 : $end - $this->position + 1));
    }
    public function write(string $data): int
    {
        $this->events[] = 'write';
        $count = $this->failWrite ? 0 : min(strlen($data), $this->writeLimit);
        $this->written .= substr($data, 0, $count);
        return $count;
    }
}

function pipelineFixtureResponse(string $id, bool $head = true, string $body = 'Fixture'): string
{
    return ($head ? '221' : '220') . ' 0 <' . $id . "> follows\r\n" . $body . "\r\n.\r\n";
}

foreach ([1, 2, 32, 64, 128] as $depth) {
    $ids = array_map(static fn (int $n): string => 'pipe-' . $n . '@example.invalid', range(1, 131));
    $socket = new PipelineSocketFixture(implode('', array_map(pipelineFixtureResponse(...), $ids)));
    $connection = new PipelineConnection('fixture.invalid', 119, false, $socket);
    $generator = $connection->articles($ids, true, $depth);
    $generator->rewind();
    check(substr_count($socket->written, "HEAD ") === $depth, 'Pipeline schrijft exact eerste venster voor verwerking bij diepte ' . $depth);
    $results = iterator_to_array($generator);
    check(count($results) === 131 && array_unique($results) === ['Fixture']
        && substr_count($socket->written, "HEAD ") === 131
        && count(array_filter($socket->events, static fn (string $event): bool => $event === 'write')) === (int) ceil(131 / $depth),
        'Alle antwoorden exact op volgorde, begrensde vensters en laatste gedeeltelijke venster bij diepte ' . $depth);
}
$socket = new PipelineSocketFixture(pipelineFixtureResponse('dot@example.invalid', false, "..dot\r\n\r\n" . str_repeat('x', 20000)));
$socket->writeLimit = 3;
$results = iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, $socket))->articles(['dot@example.invalid'], false, 32));
check($results === [".dot\r\n\r\n" . str_repeat('x', 20000)]
    && $socket->written === "ARTICLE <dot@example.invalid>\r\n",
    'ARTICLE gebruikt php-nntp handler, bewaart lange/lege regels, dot-unstuffing en gedeeltelijke writes');
$socket = new PipelineSocketFixture("430 Gone\r\n" . pipelineFixtureResponse('second@example.invalid')
    . "423 Gone\r\n" . pipelineFixtureResponse('fourth@example.invalid'));
$results = iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, $socket))->articles(
    ['first@example.invalid', 'second@example.invalid', 'third@example.invalid', 'fourth@example.invalid'], true, 32));
check($results[0] instanceof PipelineException && $results[0]->getCode() === 430 && $results[1] === 'Fixture'
    && $results[2] instanceof PipelineException && $results[2]->getCode() === 423 && $results[3] === 'Fixture',
    'Ontbrekende artikelen 423/430 verschuiven latere pipeline-antwoorden niet');
foreach ([
    '503 Temporary error' . "\r\n",
    'not a status' . "\r\n",
    '220 0 <first@example.invalid> wrong command' . "\r\n.\r\n",
    '221 0 <wrong@example.invalid> mismatch' . "\r\n.\r\n",
    '221 0 <first@example.invalid> truncated' . "\r\nbody\r\n",
    '221 0 <first@example.invalid> incomplete line' . "\r\npartial",
    str_repeat('x', 513) . "\r\n",
    "221 0 <first@example.invalid> huge line\r\n" . str_repeat('x', 1048577) . "\r\n.\r\n",
    "221 0 <first@example.invalid> huge body\r\n" . str_repeat(str_repeat('x', 65534) . "\r\n", 257) . ".\r\n",
] as $input) {
    try {
        iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, new PipelineSocketFixture($input)))
            ->articles(['first@example.invalid'], true, 32));
        check(false, 'Onbruikbare pipeline-response moet falen');
    } catch (PipelineException $exception) {
        check(true, 'Onverwachte status, verkeerde identiteit, afgebroken/te groot antwoord faalt expliciet');
    }
}
$socket = new PipelineSocketFixture(pipelineFixtureResponse('first@example.invalid'));
$socket->failWrite = true;
try {
    iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, $socket))->articles(['first@example.invalid'], true, 32));
    check(false, 'Nul-byte write moet falen');
} catch (PipelineException $exception) {
    check($socket->position === 0, 'Nul-byte write faalt zonder antwoord te lezen');
}
foreach ([0, 129, -1] as $depth) {
    rejects(static fn () => iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, new PipelineSocketFixture('')))
        ->articles(['first@example.invalid'], true, $depth)), 'Pipeline weigert diepte buiten 1-128');
}
foreach (["bad\r\nGROUP unsafe", '<already-bracketed@example.invalid>', 'missing-domain', str_repeat('a', 251) . '@b'] as $badId) {
    $socket = new PipelineSocketFixture('');
    rejects(static fn () => iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, $socket))
        ->articles([$badId], true, 32)), 'Pipeline weigert ongeldige/injecteerbare message-ID voor transport');
    check($socket->written === '', 'Ongeldige invoer schrijft geen bytes naar NNTP');
}
check(iterator_to_array((new PipelineConnection('fixture.invalid', 119, false, new PipelineSocketFixture('')))
    ->articles([], true, 128)) === [], 'Lege batch verstuurt geen NNTP-verzoeken');
$pipelineRequest = new \NnSpotty\Http\Request('POST', '/admin/settings', [], ['pipeline_depth' => ''], '127.0.0.1');
check($pipelineRequest->hasInput('pipeline_depth') && !$pipelineRequest->hasInput('missing')
    && $pipelineRequest->inputScalar('pipeline_depth') === null,
    'Instellingen kunnen ontbrekende velden onderscheiden van expliciet lege invoer zonder scalarvalidatie te wijzigen');
