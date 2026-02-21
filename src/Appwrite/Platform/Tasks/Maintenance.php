<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use DateInterval;
use DateTime;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
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
            ->inject('dbForPlatform')
            ->inject('console')
            ->inject('queueForCertificates')
            ->inject('queueForDeletes')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, Document $console, Certificate $queueForCertificates, Delete $queueForDeletes): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        $interval = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'); // 1 day
        $usageStatsRetentionHourly = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days
        $cacheRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day
        $jobInitTime = System::getEnv('_APP_MAINTENANCE_START_TIME', '00:00'); // (hour:minutes)

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $delay = $next->getTimestamp() - $now->getTimestamp();

        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(\DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        Console::info('Setting loop start time to ' . $next->format("Y-m-d H:i:s.v") . '. Delaying for ' . $delay . ' seconds.');

        Console::loop(function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $usageStatsRetentionHourly, $dbForPlatform, $console, $queueForDeletes, $queueForCertificates) {
            $time = DatabaseDateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            // Iterate through project only if it was accessed in last 30 days
            $dateInterval  = DateInterval::createFromDateString('30 days');
            $before30days = (new DateTime())->sub($dateInterval);

            $dbForPlatform->foreach(
                'projects',
                function (Document $project) use ($queueForDeletes, $usageStatsRetentionHourly) {
                    $queueForDeletes
                        ->setType(DELETE_TYPE_MAINTENANCE)
                        ->setProject($project)
                        ->setUsageRetentionHourlyDateTime(DatabaseDateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
                        ->trigger();
                },
                [
                    Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                    Query::limit(100),
                    Query::greaterThanEqual('accessedAt', DatabaseDateTime::format($before30days)),
                    Query::orderAsc('teamInternalId'),
                ]
            );

            $queueForDeletes
                ->setType(DELETE_TYPE_MAINTENANCE)
                ->setProject($console)
                ->setUsageRetentionHourlyDateTime(DatabaseDateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly))
                ->trigger();

            $this->notifyDeleteConnections($queueForDeletes);
            $this->renewCertificates($dbForPlatform, $queueForCertificates);
            $this->notifyDeleteCache($cacheRetention, $queueForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $queueForDeletes);
            $this->notifyDeleteCSVExports($queueForDeletes);
        }, $interval, $delay);
    }

    private function notifyDeleteConnections(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_REALTIME)
            ->setDatetime(DatabaseDateTime::addSeconds(new \DateTime(), -60))
            ->trigger();
    }

    private function notifyDeleteCSVExports(Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_CSV_EXPORTS)
            ->trigger();
    }

    private function renewCertificates(Database $dbForPlatform, Certificate $queueForCertificate): void
    {
        $time = DatabaseDateTime::now();

        $certificates = $dbForPlatform->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);


        if (\count($certificates) > 0) {
            Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

            // TODO: (@Meldiron) Remove after 1.7.x migration
            $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';

            foreach ($certificates as $certificate) {
                $domain = $certificate->getAttribute('domain');
                $rule = $isMd5
                    ? $dbForPlatform->getDocument('rules', md5($domain))
                    : $dbForPlatform->findOne('rules', [
                        Query::equal('domain', [$domain]),
                    ]);

                if ($rule->isEmpty() || $rule->getAttribute('region') !== System::getEnv('_APP_REGION', 'default')) {
                    continue;
                }

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
            ->setDatetime(DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }

    private function notifyDeleteSchedules($interval, Delete $queueForDeletes): void
    {
        $queueForDeletes
            ->setType(DELETE_TYPE_SCHEDULES)
            ->setDatetime(DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval))
            ->trigger();
    }
}
