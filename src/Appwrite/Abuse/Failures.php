<?php

namespace Appwrite\Abuse;

use Utopia\Abuse\Adapter\TimeLimit;

/**
 * The failure limits one request was checked against, held until its outcome is known. The shared api hook
 * adds them on arrival, once the project and user resolved; the error hook counts them if the request fails.
 */
final class Failures
{
    /**
     * @var list<array{Limit, array<string, string>, int}>
     */
    private array $pending = [];

    /**
     * @param array<string, string> $params
     */
    public function add(Limit $limit, array $params, int $max): void
    {
        $this->pending[] = [$limit, $params, $max];
    }

    /**
     * @param callable(string, int, int, callable(TimeLimit): mixed): mixed $timelimit
     */
    public function count(callable $timelimit): void
    {
        foreach ($this->pending as [$limit, $params, $max]) {
            try {
                $timelimit($limit->key, $max, $limit->seconds, fn (TimeLimit $timeLimit) => $timeLimit->withParams($params)->check());
            } catch (\Throwable $th) {
                // Thrown from an error hook, this would turn the client's error into a 500.
                \error_log((string) $th);
            }
        }
    }
}
