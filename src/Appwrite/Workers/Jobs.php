<?php

declare(strict_types=1);

namespace Appwrite\Workers;

/**
 * Resolves per-queue worker jobs for {@see app/worker.php}.
 *
 * Combined mode (`all` / many queues) keeps each queue's configured
 * `coroutines`. Dedicated mode still allows `_APP_WORKER_MAX_COROUTINES` to
 * override, except for the queues in PINNED.
 */
final class Jobs
{
    /**
     * Queues that keep their configured coroutines in a dedicated container:
     * parallel `databases` jobs risk adapter deadlocks on schema mutations, and
     * parallel `maintenance` jobs would only add to the same deletes backlog.
     */
    private const array PINNED = ['databases', 'maintenance'];

    /**
     * @param list<string> $workers Worker action names already selected to run
     * @param array<string, array{queue: string, queueEnv?: string, coroutines?: int}> $config
     * @param callable(string, mixed=): mixed $env Compatible with {@see \Utopia\System\System::getEnv}
     * @return array<string, array{queue: string, coroutines: int}>
     */
    public static function resolve(array $workers, array $config, callable $env): array
    {
        $jobs = [];
        $single = \count($workers) === 1;

        foreach ($workers as $name) {
            if (!isset($config[$name])) {
                throw new \InvalidArgumentException('Unknown worker: ' . $name);
            }

            $spec = $config[$name];
            $queue = $env($spec['queueEnv'] ?? '_APP_QUEUE_NAME', $spec['queue']);
            if ($queue === false || $queue === null || $queue === '') {
                $queue = $spec['queue'];
            }

            $coroutines = max(1, (int) ($spec['coroutines'] ?? 1));

            // Combined: never apply the global override, so every queue keeps its
            // own cap. Dedicated: the override applies unless the queue is pinned.
            if ($single && !\in_array($name, self::PINNED, true)) {
                $override = $env('_APP_WORKER_MAX_COROUTINES');
                if ($override !== false && $override !== null && $override !== '') {
                    $coroutines = max(1, (int) $override);
                }
            }

            $jobs[$name] = [
                'queue' => (string) $queue,
                'coroutines' => $coroutines,
            ];
        }

        return $jobs;
    }
}
