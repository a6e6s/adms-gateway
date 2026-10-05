<?php

namespace App\Services\Adms;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Throwable;

class AttendancePayloadParser
{
    /**
     * @return Generator<int, array{line: int, fields: list<string>, error: ?string}>
     */
    public function rows(string $payload, string $timezone): Generator
    {
        $zone = new DateTimeZone($timezone);
        $lines = preg_split('/\r\n|\n|\r/', $payload);

        if ($lines === false) {
            throw new \RuntimeException('Could not split the attendance payload.');
        }

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $fields = explode("\t", $line);
            $error = null;

            if (count($fields) < 2 || $fields[0] === '') {
                $error = 'Expected at least a PIN and a timestamp.';
            } else {
                try {
                    $occurredAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $fields[1], $zone);
                    $dateErrors = DateTimeImmutable::getLastErrors();

                    if ($occurredAt === false
                        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                        || $occurredAt->format('Y-m-d H:i:s') !== $fields[1]) {
                        $error = 'Timestamp must use YYYY-MM-DD HH:MM:SS.';
                    }
                } catch (Throwable) {
                    $error = 'Timestamp or device timezone is invalid.';
                }
            }

            yield [
                'line' => $index + 1,
                'fields' => $fields,
                'error' => $error,
            ];
        }
    }
}
