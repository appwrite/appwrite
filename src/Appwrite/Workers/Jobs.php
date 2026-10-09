<?php

declare(strict_types=1);

namespace Appwrite\Workers;

/**
 * Resolves per-queue worker jobs for {@see app/worker.php}.
 *
 * Combined mode keeps each queue's configured concurrency. Dedicated mode
 * allows `_APP_WORKER_MAX_COROUTINES` to override it.
 */
final class Jobs
{
    /**
     * @param list<string> $workers Worker action names already selected to run
     * @param array<string, array{queue: string, queueEnv?: string, coroutines?: int}> $config
     * @param callable(string, mixed=): mixed $env Compatible with {@see \Utopia\System\System::getEnv}
     * @return array<string, array{queue: string, coroutines: int}>
     */
    public static function resolve(array $workers, array $config, callable $env): array
    {
        // Database DDL mutexes are process-local, including in combined mode.
        if (in_array('databases', $workers, true) && (int) $env('_APP_WORKERS_NUM', 1) !== 1) {
            throw new \InvalidArgumentException('Database workers require one process for DDL mutexes.');
        }

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

            if ($single) {
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
