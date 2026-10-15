<?php

namespace Utopia\Usage;

use InvalidArgumentException;

/**
 * Exact bounded membership captured by one adapter snapshot query.
 *
 * Ingestion IDs are generated inside addSamples() before transport. Each
 * captured entry also binds the canonical and payload hashes, so a repeated
 * HTTP request retains the same entry while any changed row is excluded.
 */
final readonly class SampleWatermark
{
    /**
     * @param list<string> $entries
     */
    public function __construct(
        private SampleRange $range,
        private array $entries,
        private bool $truncated,
    ) {
        if ($entries !== array_values(array_unique($entries))) {
            throw new InvalidArgumentException('entries must be a unique list');
        }

        foreach ($entries as $entry) {
            if (preg_match('/^[a-f0-9]{32}:[a-f0-9]{64}:[a-f0-9]{64}$/', $entry) !== 1) {
                throw new InvalidArgumentException('entries must bind an ingestion ID, canonical ID and payload hash');
            }
        }
    }

    public function matches(SampleRange $range): bool
    {
        return $this->range->environment === $range->environment
            && $this->range->region === $range->region
            && $this->range->projectInternalId === $range->projectInternalId
            && $this->range->databaseInternalId === $range->databaseInternalId
            && $this->range->member === $range->member
            && $this->range->generation === $range->generation
            && $this->range->metric === $range->metric
            && $this->range->firstSequence === $range->firstSequence
            && $this->range->lastSequence === $range->lastSequence
            && $this->range->getFormattedIntervalStart() === $range->getFormattedIntervalStart()
            && $this->range->getFormattedIntervalEnd() === $range->getFormattedIntervalEnd();
    }

    /** @return list<string> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    public function isTruncated(): bool
    {
        return $this->truncated;
    }
}
