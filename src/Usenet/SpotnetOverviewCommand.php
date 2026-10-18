<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use Rvdv\Nntp\Command\Command;
use Rvdv\Nntp\Response\MultiLineResponse;
use Rvdv\Nntp\Response\Response;
use UnexpectedValueException;

final class SpotnetOverviewCommand extends Command
{
    /** @param array<string, bool> $format */
    public function __construct(
        private readonly int $from,
        private readonly int $to,
        private readonly array $format
    ) {
        parent::__construct(true);
    }

    public function __invoke(): string
    {
        return 'XOVER ' . $this->from . '-' . $this->to;
    }

    /** @return list<array<string, string>> */
    public function onOverviewInformationFollows(MultiLineResponse $response): array
    {
        $rows = [];
        foreach ($response->getLines() as $line) {
            $fields = explode("\t", $line);
            $format = ['number' => false] + $this->format;
            if (count($fields) < count($format)) {
                throw new UnexpectedValueException('Onvolledige NNTP overview; cursor is niet bijgewerkt.');
            }
            $row = [];
            foreach ($format as $name => $fullHeader) {
                $value = array_shift($fields);
                if ($fullHeader) {
                    $parts = explode(':', $value, 2);
                    if (count($parts) !== 2) {
                        throw new UnexpectedValueException('Ongeldig volledig overview-veld.');
                    }
                    $value = ltrim($parts[1]);
                }
                $row[strtolower(str_replace('_', '-', trim($name, ':')))] = $value;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array{} */
    public function onNoArticlesSelected(Response $response): array
    {
        // An expired article range is empty, not a failed connection.
        return [];
    }
}
