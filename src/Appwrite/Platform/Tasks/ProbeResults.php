<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Alert\Prober;
use Appwrite\Event\Event;
use Appwrite\Event\Message\ProbeResult as ProbeResultMessage;
use Appwrite\Event\Publisher\Alert as AlertPublisher;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Platform\Action;
use Utopia\Queue\Queue;
use Utopia\Span\Span;
use Utopia\System\System;

/**
 * Hands each check the prober pushes onto `synthetics.probes.results` to the
 * alerts worker, which records it on the monitor and opens or resolves its
 * alert. The job's namespace is the monitor's project, which the worker needs
 * to resolve the project's database. Replicas pop from the same list, so each
 * result is handed over once, and results pushed while none runs wait on it.
 */
class ProbeResults extends Action
{
    /**
     * Seconds each pop blocks for before it is retried.
     */
    private const int POP_TIMEOUT = 5;

    public static function getName(): string
    {
        return 'probe-results';
    }

    public function __construct()
    {
        $this
            ->desc('Hand the prober\'s checks to the alerts worker')
            ->inject('publisherForAlerts')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(AlertPublisher $publisherForAlerts, Database $dbForPlatform): never
    {
        $dsn = System::getEnv('_APP_CONNECTIONS_PROBER', '');
        if ($dsn === '') {
            throw new \RuntimeException('_APP_CONNECTIONS_PROBER is not set, so there is no prober to read results from.');
        }

        $queue = new Queue(System::getEnv('_APP_ALERTS_QUEUE_NAME', Event::ALERTS_QUEUE_NAME));
        $redis = null;

        Console::success('Handing prober results to the alerts worker');

        while (true) {
            try {
                $redis ??= Prober::connect($dsn);
                $popped = $redis->brPop([Prober::RESULTS], self::POP_TIMEOUT);
            } catch (\RedisException $error) {
                // Redis restarting; results wait on the list until it is back.
                Console::warning('Prober Redis unreachable: ' . $error->getMessage());
                $redis = null;
                \sleep(1);
                continue;
            }
            if (!\is_array($popped) || !isset($popped[1])) {
                continue;
            }

            try {
                $result = \json_decode((string) $popped[1], true);
                if (!\is_array($result) || !\is_string($result['namespace'] ?? null) || !\is_string($result['name'] ?? null)) {
                    continue;
                }

                // A result for a project deleted since its job was pushed has nothing to record on.
                $project = $dbForPlatform->getAuthorization()->skip(fn () => $dbForPlatform->getDocument('projects', $result['namespace']));
                if ($project->isEmpty()) {
                    continue;
                }

                $publisherForAlerts->publish($queue, new ProbeResultMessage($project, $result['name'], $result));
            } catch (\Throwable $error) {
                // One result lost costs one check; the monitor runs again on its next interval.
                Span::init('probe.results');
                Span::current()?->finish(error: $error);
            }
        }
    }
}
