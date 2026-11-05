<?php

namespace Appwrite\Realtime;

/**
 * Sums a worker's realtime usage metrics per project so they are published once per
 * flush instead of on every open, close and message, each of which would otherwise
 * read the project and enqueue a message through the worker's small connection pools.
 */
class Usage
{
    /** @var array<string, array<string, int>> projectId => metric => value */
    private array $metrics = [];

    private bool $stopped = false;

    /**
     * @param \Closure(array<string, int>, string): void $publish publishes one project's metrics
     */
    public function __construct(private readonly \Closure $publish)
    {
    }

    /**
     * @param array<string, int> $metrics
     */
    public function add(array $metrics, string $projectId): void
    {
        if ($this->stopped) {
            ($this->publish)($metrics, $projectId);
            return;
        }

        foreach ($metrics as $metric => $value) {
            $this->metrics[$projectId][$metric] = ($this->metrics[$projectId][$metric] ?? 0) + $value;
        }
    }

    public function flush(): void
    {
        $pending = $this->metrics;
        $this->metrics = [];

        foreach ($pending as $projectId => $metrics) {
            $metrics = \array_filter($metrics, fn (int $value): bool => $value !== 0);
            if (!empty($metrics)) {
                ($this->publish)($metrics, (string) $projectId);
            }
        }
    }

    /**
     * Publish what is pending, and everything added afterwards as it arrives.
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->flush();
    }
}
