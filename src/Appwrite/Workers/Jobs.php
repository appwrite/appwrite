<?php

declare(strict_types=1);

namespace Appwrite\Workers;

/**
 * Resolves per-queue worker jobs for {@see app/worker.php}.
 *
 * Combined mode (`all` / many queues) keeps each queue's configured
 * `maxCoroutines` so `databases` stays at 1. Dedicated mode still allows
 * `_APP_WORKER_MAX_COROUTINES` to override — except for `databases`, where
 * parallelism risks adapter deadlocks on schema mutations.
 */
final class Jobs
{
    /**
     * @param list<string> $workers Worker action names already selected to run
     * @param array<string, array{queue: string, queueEnv?: string, maxCoroutines?: int}> $config
     * @param callable(string, mixed=): mixed $env Compatible with {@see \Utopia\System\System::getEnv}
     * @return array<string, array{queue: string, maxCoroutines: int}>
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

            $maxCoroutines = max(1, (int) ($spec['maxCoroutines'] ?? 1));

            // Combined: never apply the global override — databases must stay at 1
            // while other queues keep their own caps. Dedicated: override is allowed
            // for every queue except databases.
            if ($single && $name !== 'databases') {
                $override = $env('_APP_WORKER_MAX_COROUTINES');
                if ($override !== false && $override !== null && $override !== '') {
                    $maxCoroutines = max(1, (int) $override);
                }
            }

            $jobs[$name] = [
                'queue' => (string) $queue,
                'maxCoroutines' => $maxCoroutines,
            ];
        }

        return $jobs;
    }
}
