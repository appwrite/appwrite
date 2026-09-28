<?php

namespace Utopia\Usage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class SampleRange
{
    public function __construct(
        public string $environment,
        public string $region,
        public string $projectInternalId,
        public string $databaseInternalId,
        public string $member,
        public string $generation,
        public string $metric,
        public int $firstSequence,
        public int $lastSequence,
        public DateTimeImmutable $intervalStart,
        public DateTimeImmutable $intervalEnd,
    ) {
        foreach ([
            'environment' => $environment,
            'region' => $region,
            'projectInternalId' => $projectInternalId,
            'databaseInternalId' => $databaseInternalId,
            'member' => $member,
            'generation' => $generation,
            'metric' => $metric,
        ] as $field => $value) {
            if ($value === '') {
                throw new InvalidArgumentException("{$field} cannot be empty");
            }
        }

        if ($firstSequence < 0 || $lastSequence < $firstSequence) {
            throw new InvalidArgumentException('Invalid sample sequence range');
        }

        if ($intervalStart >= $intervalEnd) {
            throw new InvalidArgumentException('intervalStart must be before intervalEnd');
        }
    }

    public function getFormattedIntervalStart(): string
    {
        return $this->formatDateTime($this->intervalStart);
    }

    public function getFormattedIntervalEnd(): string
    {
        return $this->formatDateTime($this->intervalEnd);
    }

    private function formatDateTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
