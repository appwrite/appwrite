<?php

namespace Utopia\Usage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class Sample
{
    public function __construct(
        public string $environment,
        public string $region,
        public string $projectInternalId,
        public string $databaseInternalId,
        public string $member,
        public string $generation,
        public int $sequence,
        public string $metric,
        public DateTimeImmutable $intervalStart,
        public DateTimeImmutable $intervalEnd,
        public int $value,
        public int $eventVersion,
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

        if ($sequence < 0) {
            throw new InvalidArgumentException('sequence cannot be negative');
        }

        if ($eventVersion < 1 || $eventVersion > 4_294_967_295) {
            throw new InvalidArgumentException('eventVersion must fit an unsigned 32-bit integer');
        }

        if ($intervalStart >= $intervalEnd) {
            throw new InvalidArgumentException('intervalStart must be before intervalEnd');
        }
    }

    /**
     * Canonical stream identity. A retry of one observation must retain this
     * ID even if a faulty producer changes its payload, so readers can expose
     * the conflict rather than counting both values.
     *
     */
    public function getId(): string
    {
        return $this->hashParts([
            $this->environment,
            $this->region,
            $this->projectInternalId,
            $this->databaseInternalId,
            $this->member,
            $this->generation,
            $this->sequence,
            $this->metric,
        ]);
    }

    /**
     * Hash every money-bearing field. Equal IDs with different payload hashes
     * are conflicting observations and make the stream incomplete.
     *
     */
    public function getPayloadHash(): string
    {
        return $this->hashParts([
            $this->getId(),
            $this->formatDateTime($this->intervalStart),
            $this->formatDateTime($this->intervalEnd),
            $this->value,
            $this->eventVersion,
        ]);
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

    /**
     * Length-prefixing makes the digest unambiguous and reproducible by
     * producers in other languages without depending on JSON encoding rules.
     *
     * @param list<int|string> $parts
     */
    private function hashParts(array $parts): string
    {
        $encoded = '';

        foreach ($parts as $part) {
            $value = (string) $part;
            $encoded .= strlen($value) . ':' . $value;
        }

        return hash('sha256', $encoded);
    }
}
