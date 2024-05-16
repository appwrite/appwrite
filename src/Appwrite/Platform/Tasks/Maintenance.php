<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\System\System;

class Maintenance extends Action
{
    public static function getName(): string
    {
        return 'maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules maintenance tasks and publishes them to our queues')
            ->inject('dbForConsole')
            ->inject('queueForCertificates')
            ->inject('queueForDeletes')
            ->callback(fn (Database $dbForConsole, Certificate $queueForCertificates, Delete $queueForDeletes) => $this->action($dbForConsole, $queueForCertificates, $queueForDeletes));
    }

    public function action(Database $dbForConsole, Certificate $queueForCertificates, Delete $queueForDeletes): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        // # of days in seconds (1 day = 86400s)
        $interval = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400');
        $delay = (int) System::getEnv('_APP_MAINTENANCE_DELAY', '0');
        $usageStatsRetentionHourly = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days
        $cacheRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day

        Console::loop(function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $usageStatsRetentionHourly, $dbForConsole, $queueForDeletes, $queueForCertificates) {
            $time = DateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            $this->foreachProject($dbForConsole, function (Document $project) use ($queueForDeletes, $usageStatsRetentionHourly) {
                $queueForDeletes->setProject($project);

                $this->notifyDeleteExecutionLogs($queueForDeletes);
                $this->notifyDeleteAbuseLogs($queueForDeletes);
                $this->notifyDeleteAuditLogs($queueForDeletes);
                $this->notifyDeleteUsageStats($usageStatsRetentionHourly, $queueForDeletes);
                $this->notifyDeleteExpiredSessions($queueForDeletes);
            });

            $this->notifyDeleteConnections($queueForDeletes);
            $this->renewCertificates($dbForConsole, $queueForCertificates);
            $this->notifyDeleteCache($cacheRetention, $queueForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $queueForDeletes);
            $this->notifyDeleteTargets($queueForDeletes);
        }, $interval, $delay);
    }

    protected function foreachProject(Database $dbForConsole, callable $callback): void
    {
        // TODO: @Meldiron name of this method no longer matches. It does not delete, and it gives whole document
        $count = 0;
        $chunk = 0;
        $limit = 50;
        $sum = $limit;
        $executionStart = \microtime(true);

        while ($sum === $limit) {
            $projects = $dbForConsole->find('projects', [Query::limit($limit), Query::offset($chunk * $limit)]);

            $chunk++;

            /** @var string[] $projectIds */
            $sum = count($projects);

            foreach ($projects as $project) {
                $callback($project);
                $count++;
            }
        }

        $executionEnd = \microtime(true);
        Console::info("Found {$count} projects " . ($executionEnd - $executionStart) . " seconds");
    }

    private function notifyDeleteExecutionLogs(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_EXECUTIONS)
            ->trigger();
    }

    private function notifyDeleteAbuseLogs(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_ABUSE)
            ->trigger();
    }

    private function notifyDeleteAuditLogs(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_AUDIT)
            ->trigger();
    }

    private function notifyDeleteUsageStats(int $usageStatsRetentionHourly, Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_USAGE)
            ->setUsageRetentionHourlyDateTime(DateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
            ->trigger();
    }

    private function notifyDeleteConnections(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_REALTIME)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -60))
            ->trigger();
    }

    private function notifyDeleteExpiredSessions(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_SESSIONS)
            ->trigger();
    }

    private function renewCertificates(Database $dbForConsole, Certificate $queueForCertificate): void
    {
        $time = DateTime::now();

        $certificates = $dbForConsole->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);


        if (\count($certificates) > 0) {
            Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

            foreach ($certificates as $certificate) {
                $queueForCertificate
                    ->setDomain(new Document([
                        'domain' => $certificate->getAttribute('domain')
                    ]))
                    ->trigger();
            }
        } else {
            Console::info("[{$time}] No certificates for renewal.");
        }
    }

    private function notifyDeleteCache($interval, Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_CACHE_BY_TIMESTAMP)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteSchedules($interval, Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_SCHEDULES)
            ->setDatetime(DateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteTargets(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_EXPIRED_TARGETS)
            ->trigger();
    }
}
