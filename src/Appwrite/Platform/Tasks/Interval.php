<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use DateTime;
use Swoole\Timer;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
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

        $intervalDomainVerification = (int) System::getEnv('_APP_INTERVAL_DOMAIN_VERIFICATION', '120'); // 2 minutes
        $intervalCleanupStaleExecutions = (int) System::getEnv('_APP_INTERVAL_CLEANUP_STALE_EXECUTIONS', '300'); // 5 minutes

        Timer::tick($intervalDomainVerification * 1000, function () use ($dbForPlatform, $queueForCertificates) {
            $this->verifyDomain($dbForPlatform, $queueForCertificates);
        });

        Timer::tick($intervalCleanupStaleExecutions * 1000, function () use ($dbForPlatform, $getProjectDB) {
            $this->cleanupStaleExecutions($dbForPlatform, $getProjectDB);
        });
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

        if (\count($rules) === 0) {
            Console::info("[{$time}] No rules for domain verification.");
            return; // No rules to verify
        }

        Console::info("[{$time}] Found " . \count($rules) . " rules for domain verification, scheduling jobs.");

        foreach ($rules as $rule) {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->setAction(Certificate::ACTION_DOMAIN_VERIFICATION)
                ->trigger();
        }
    }

    private function cleanupStaleExecutions(Database $dbForPlatform, callable $getProjectDB): void
    {
        $time = DatabaseDateTime::now();
        $staleThreshold = DatabaseDateTime::addSeconds(new DateTime(), -1200); // 20 minutes ago

        Console::info("[{$time}] Starting cleanup of stale executions");

        $dbForPlatform->foreach(
            'projects',
            function (Document $project) use ($getProjectDB, $time, $staleThreshold) {
                try {
                    $dbForProject = $getProjectDB($project);

                    $staleExecutions = $dbForProject->find('executions', [
                        Query::equal('status', ['processing']),
                        Query::lessThan('$createdAt', $staleThreshold),
                        Query::limit(100),
                    ]);

                    if (\count($staleExecutions) === 0) {
                        return;
                    }

                    Console::info("[{$time}] Found " . \count($staleExecutions) . " stale executions in project {$project->getId()}");

                    foreach ($staleExecutions as $execution) {
                        $execution->setAttribute('status', 'failed');
                        $execution->setAttribute('errors', 'Execution timed out');
                        $dbForProject->updateDocument('executions', $execution->getId(), $execution);
                    }
                } catch (\Throwable $th) {
                    Console::error("[{$time}] Failed to cleanup stale executions for project {$project->getId()}: " . $th->getMessage());
                }
            },
            [
                Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                Query::limit(100),
            ]
        );

        Console::info("[{$time}] Completed cleanup of stale executions");
    }
}
