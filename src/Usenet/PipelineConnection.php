<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use Rvdv\Nntp\Command\ArticleCommand;
use Rvdv\Nntp\Command\HeadCommand;
use Rvdv\Nntp\Connection\Connection;
use Rvdv\Nntp\Exception\RuntimeException as NntpException;
use Rvdv\Nntp\Response\MultiLineResponse;
use Rvdv\Nntp\Response\Response;
use Rvdv\Nntp\Socket\SocketInterface;

final class PipelineConnection extends Connection
{
    public function __construct(string $host, int $port, bool $secure, private readonly SocketInterface $transport)
    {
        parent::__construct($host, $port, $secure, $transport);
    }

    /**
     * Windows are fully drained before sending more commands, bounding outstanding responses.
     * A fatal read/write error makes this connection unusable; the retrieve must disconnect.
     *
     * @param list<string> $messageIds
     * @return \Generator<int, string|NntpException>
     */
    public function articles(array $messageIds, bool $headersOnly, int $depth): \Generator
    {
        if ($depth < 1 || $depth > 128) {
            throw new \InvalidArgumentException('Pipeline-diepte moet tussen 1 en 128 liggen.');
        }
        $commands = [];
        foreach ($messageIds as $id) {
            if (SpotnetParser::messageId($id) !== $id) {
                throw new \InvalidArgumentException('Ongeldig pipeline message-ID.');
            }
            $commands[] = $headersOnly ? new HeadCommand('<' . $id . '>') : new ArticleCommand('<' . $id . '>');
        }
        foreach (array_chunk($commands, $depth, true) as $window) {
            $wire = '';
            foreach ($window as $command) $wire .= $command() . "\r\n";
            while ($wire !== '') {
                $written = $this->transport->write($wire);
                if ($written < 1 || $written > strlen($wire)) throw new NntpException('NNTP pipeline schrijven mislukt.');
                $wire = substr($wire, $written);
            }
            foreach ($window as $index => $command) {
                $status = $this->line(512);
                if (!preg_match('/^(\d{3}) /D', $status, $match)) {
                    throw new NntpException('Ongeldige NNTP pipeline-statusregel.');
                }
                $code = (int) $match[1];
                if (in_array($code, [423, 430], true)) {
                    yield $index => new NntpException('Artikel niet meer beschikbaar.', $code);
                    continue;
                }
                if ($code !== ($headersOnly ? 221 : 220)) {
                    throw new NntpException('NNTP pipeline gaf onverwachte status ' . $code . '.', $code);
                }
                if (!preg_match('/^\d{3} \d+ <([^<>]+)>/', $status, $identity)
                    || $identity[1] !== $messageIds[$index]) {
                    throw new NntpException('NNTP pipeline-antwoord hoort niet bij het aangevraagde message-ID.');
                }
                $response = Response::createFromString($status . "\r\n");
                $lines = [];
                $bytes = 0;
                while (($line = $this->line(1048576)) !== '.') {
                    $bytes += strlen($line) + 2;
                    if ($bytes > 16777216) throw new NntpException('NNTP pipeline-antwoord overschrijdt 16 MiB.');
                    $lines[] = str_starts_with($line, '..') ? substr($line, 1) : $line;
                }
                $multiline = new MultiLineResponse($response, $lines);
                yield $index => $command instanceof HeadCommand
                    ? $command->onHeadFollows($multiline) : $command->onArticleFollows($multiline);
            }
        }
    }

    private function line(int $limit): string
    {
        $line = '';
        do {
            if ($this->transport->eof()) throw new NntpException('NNTP pipeline-verbinding afgebroken.');
            $part = $this->transport->gets(min(8192, $limit + 3 - strlen($line)));
            if ($part === '') throw new NntpException('NNTP pipeline lezen mislukt of timeout.');
            $line .= $part;
            if (strlen($line) > $limit + 2) throw new NntpException('NNTP pipeline-regel is te lang.');
        } while (!str_ends_with($line, "\r\n"));
        return substr($line, 0, -2);
    }
}
