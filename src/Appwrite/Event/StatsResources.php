<?php

namespace Appwrite\Event;

use Utopia\Queue\Publisher;
use Utopia\System\System;

class StatsResources extends Event
{
    protected bool $critical = false;

    /**
     * Pre-computed gauge metric snapshots to write to the stats collection. When non-empty,
     * the StatsResources worker takes the fast path: it writes these directly via
     * upsertDocuments (replace semantics) and skips the standard counting work.
     *
     * Each entry is a tuple of (metric key, value). The worker writes one stats document per
     * (metric, period) tuple using the project's region.
     *
     * @var array<int, array{metric: string, value: int}>
     */
    protected array $gauges = [];

    public function __construct(protected Publisher $publisher)
    {
        parent::__construct($publisher);

        $this
            ->setQueue(System::getEnv('_APP_STATS_RESOURCES_QUEUE_NAME', Event::STATS_RESOURCES_QUEUE_NAME))
            ->setClass(System::getEnv('_APP_STATS_RESOURCES_CLASS_NAME', Event::STATS_RESOURCES_CLASS_NAME));
    }

    /**
     * Set the full set of pre-computed gauge metrics for this message. Replaces any
     * previously-set gauges.
     *
     * @param array<int, array{metric: string, value: int}> $gauges
     */
    public function setGauges(array $gauges): self
    {
        $this->gauges = $gauges;
        return $this;
    }

    /**
     * Append a single pre-computed gauge metric to this message.
     */
    public function addGauge(string $metric, int $value): self
    {
        $this->gauges[] = ['metric' => $metric, 'value' => $value];
        return $this;
    }

    /**
     * @return array<int, array{metric: string, value: int}>
     */
    public function getGauges(): array
    {
        return $this->gauges;
    }

    /**
     * Prepare the payload for the usage event.
     *
     * @return array
     */
    protected function preparePayload(): array
    {
        return [
            'project' => $this->project,
            'gauges' => $this->gauges,
        ];
    }
}
