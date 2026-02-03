<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use DateTime;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Span\Span;
use Utopia\System\System;

class Interval extends Action
{
    public static function getName(): string
    {
        return 'interval';
    }

    public function __construct()
    {
        $this
          ->desc('Schedules tasks on regular intervals by publishing them to our queues')
          ->inject('dbForPlatform')
          ->inject('getProjectDB')
          ->inject('queueForCertificates')
          ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, callable $getProjectDB, Certificate $queueForCertificates): void
    {
        Console::title('Interval V1');
        Console::success(APP_NAME . ' interval process v1 has started');

        $timers = $this->runTasks($dbForPlatform, $getProjectDB, $queueForCertificates);

        $chan = new Channel(1);
        Process::signal(SIGTERM, function () use ($chan) {
            $chan->push(true);
        });
        $chan->pop(); // Block the main process from exiting

        // Graceful shutdown when SIGTERM is received
        foreach ($timers as $timer) {
            Timer::clear($timer);
        }
    }

    public function runTasks(Database $dbForPlatform, callable $getProjectDB, Certificate $queueForCertificates): array
    {
        $timers = [];
        $tasks = $this->getTasks();
        foreach ($tasks as $task) {
            $timers[] = Timer::tick($task['interval'], function () use ($task, $dbForPlatform, $getProjectDB, $queueForCertificates) {
                // Run each task in a coroutine to prevent blocking other timers
                go(function () use ($task, $dbForPlatform, $getProjectDB, $queueForCertificates) {
                    $taskName = $task['name'];
                    Span::init("interval.{$taskName}");
                    try {
                        $task['callback']($dbForPlatform, $getProjectDB, $queueForCertificates);
                    } catch (\Exception $e) {
                        Span::error($e);
                    } finally {
                        Span::current()->finish();
                    }
                });
            });
        }
        return $timers;
    }

    protected function getTasks(): array
    {
        $intervalDomainVerification = (int) System::getEnv('_APP_INTERVAL_DOMAIN_VERIFICATION', '120'); // 2 minutes
        $intervalCleanupStaleExecutions = (int) System::getEnv('_APP_INTERVAL_CLEANUP_STALE_EXECUTIONS', '300'); // 5 minutes

        return [
            [
                'name' => 'domainVerification',
                "callback" => function (Database $dbForPlatform, callable $getProjectDB, Certificate $queueForCertificates) {
                    $this->verifyDomain($dbForPlatform, $queueForCertificates);
                },
                'interval' => $intervalDomainVerification * 1000,
            ],
            [
                'name' => 'cleanupStaleExecutions',
                "callback" => function (Database $dbForPlatform, callable $getProjectDB, Certificate $queueForCertificates) {
                    $this->cleanupStaleExecutions($dbForPlatform, $getProjectDB);
                },
                'interval' => $intervalCleanupStaleExecutions * 1000,
            ],
        ];
    }

    private function verifyDomain(Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        $time = DatabaseDateTime::now();
        $fromTime = new DateTime('-3 days'); // Max 3 days old

        $rules = $dbForPlatform->find('rules', [
            Query::createdAfter(DatabaseDateTime::format($fromTime)),
            Query::equal('status', [RULE_STATUS_CREATED]), // Created but not verified yet
            Query::orderAsc('$updatedAt'), // Pick the ones waiting for another attempt for longest
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]), // Only current region
            Query::limit(100), // Reasonable pagination limit
        ]);

        $scanned = \count($rules);
        Span::add("interval.domainVerification.scanned", $scanned);

        if ($scanned === 0) {
            Span::add("interval.domainVerification.processed", 0);
            Span::add("interval.domainVerification.failed", 0);
            return; // No rules to verify
        }

        $processed = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            try {
                $queueForCertificates
                    ->setDomain(new Document([
                        'domain' => $rule->getAttribute('domain'),
                        'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                    ]))
                    ->setAction(Certificate::ACTION_DOMAIN_VERIFICATION)
                    ->trigger();
                $processed++;
            } catch (\Throwable $th) {
                $failed++;
            }
        }

        Span::add("interval.domainVerification.processed", $processed);
        Span::add("interval.domainVerification.failed", $failed);
    }

    private function cleanupStaleExecutions(Database $dbForPlatform, callable $getProjectDB): void
    {
        $staleThreshold = DatabaseDateTime::addSeconds(new DateTime(), -1200); // 20 minutes ago

        $scanned = 0;
        $processed = 0;
        $failed = 0;

        $dbForPlatform->foreach(
            'projects',
            function (Document $project) use ($getProjectDB, $staleThreshold, &$scanned, &$processed, &$failed) {
                try {
                    $dbForProject = $getProjectDB($project);

                    $staleExecutions = $dbForProject->find('executions', [
                        Query::equal('status', ['processing']),
                        Query::lessThan('$createdAt', $staleThreshold),
                        Query::limit(100),
                    ]);

                    $scanned += \count($staleExecutions);

                    if (\count($staleExecutions) === 0) {
                        return;
                    }

                    foreach ($staleExecutions as $execution) {
                        $execution->setAttribute('status', 'failed');
                        $execution->setAttribute('errors', 'Execution timed out');
                        $dbForProject->updateDocument('executions', $execution->getId(), $execution);
                    }

                    $processed++;
                } catch (\Throwable $th) {
                    $failed++;
                }
            },
            [
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::limit(100),
            ]
        );

        Span::add("interval.cleanupStaleExecutions.scanned", $scanned);
        Span::add("interval.cleanupStaleExecutions.processed", $processed);
        Span::add("interval.cleanupStaleExecutions.failed", $failed);
    }
}
