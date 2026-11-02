<?php

namespace Appwrite\Abuse;

use Utopia\Abuse\Adapter\TimeLimit;
use Utopia\Abuse\Result;

/**
 * The hits one request reserved on failure limits. The shared api hook reserves them on arrival with an atomic
 * check(), so a burst of concurrent requests cannot all pass before any of them counts. A request that turns out
 * not to be a failure gives its hits back; a failed one keeps them.
 */
final class Failures
{
    /**
     * @var list<array{Limit, array<string, string>, int, Result}>
     */
    private array $reserved = [];

    /**
     * @param array<string, string> $params
     */
    public function reserve(Limit $limit, array $params, int $max, Result $hit): void
    {
        $this->reserved[] = [$limit, $params, $max, $hit];
    }

    /**
     * @param callable(string, int, int, callable(TimeLimit): mixed): mixed $timelimit
     */
    public function release(callable $timelimit): void
    {
        foreach ($this->reserved as [$limit, $params, $max, $hit]) {
            try {
                $timelimit($limit->key, $max, $limit->seconds, fn (TimeLimit $timeLimit) => $timeLimit->withParams($params)->release($hit));
            } catch (\Throwable $th) {
                // Called from an error hook, a throw would turn the client's error into a 500. A hit left
                // unreleased only counts one success against the limit.
                \error_log((string) $th);
            }
        }

        $this->reserved = [];
    }
}
