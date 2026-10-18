<?php

declare(strict_types=1);

namespace NnSpotty\Usenet;

use Rvdv\Nntp\Command\Command;
use Rvdv\Nntp\Response\MultiLineResponse;
use Rvdv\Nntp\Response\Response;

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
        foreach ($response->getLines() as $index => $line) {
            $fields = explode("\t", $line);
            $fieldCount = count($fields);
            $format = ['number' => false] + $this->format;
            $row = [];
            $errors = [];
            $warnings = [];
            foreach ($format as $name => $fullHeader) {
                $key = strtolower(str_replace('_', '-', trim($name, ':')));
                // Providers may omit trailing optional overview fields.
                $value = array_shift($fields) ?? '';
                if ($fullHeader && $value !== '') {
                    $parts = explode(':', $value, 2);
                    if (count($parts) !== 2 || strtolower(str_replace('_', '-', $parts[0])) !== $key) {
                        $issue = 'Ongeldig volledig overview-veld ' . $key . '.';
                        if (in_array($key, ['date', 'message-id'], true)) {
                            $errors[] = $issue;
                        } else {
                            $warnings[] = $issue;
                        }
                        $value = '';
                    } else {
                        $value = ltrim($parts[1]);
                    }
                }
                $row[$key] = $value;
            }
            if (!isset($row['number']) || !ctype_digit($row['number'])
                || (int) $row['number'] < $this->from || (int) $row['number'] > $this->to) {
                $errors[] = 'Ongeldig artikelnummer.';
            }
            foreach (['date', 'message-id'] as $required) {
                if (trim($row[$required] ?? '') === '') {
                    $errors[] = 'Verplicht overview-veld ' . $required . ' ontbreekt.';
                }
            }
            $context = 'XOVER ' . $this->from . '-' . $this->to . ', regel ' . ($index + 1)
                . ' (' . $fieldCount . '/' . count($format) . ' velden): ';
            if ($errors !== []) {
                $row['_overview_error'] = $context . implode(' ', $errors);
            }
            if ($warnings !== []) {
                $row['_overview_warning'] = $context . implode(' ', $warnings);
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
