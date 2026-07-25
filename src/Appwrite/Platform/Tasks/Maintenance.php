<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use DateInterval;
use DateTime;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

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
            ->param('type', 'loop', new WhiteList(['loop', 'trigger']), 'How to run task. "loop" is meant for container entrypoint, and "trigger" for manual execution.')
            ->inject('dbForPlatform')
            ->inject('console')
            ->inject('publisherForCertificates')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(string $type, Database $dbForPlatform, Document $console, Certificate $publisherForCertificates, DeletePublisher $publisherForDeletes): void
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

        $action = function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $usageStatsRetentionHourly, $dbForPlatform, $console, $publisherForDeletes, $publisherForCertificates) {
            $time = DatabaseDateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            // Iterate through project only if it was accessed in last 30 days
            $dateInterval  = DateInterval::createFromDateString('30 days');
            $before30days = (new DateTime())->sub($dateInterval);

            $dbForPlatform->foreach(
                'projects',
                function (Document $project) use ($publisherForDeletes, $usageStatsRetentionHourly) {
                    $publisherForDeletes->enqueue(new DeleteMessage(
                        project: $project,
                        type: DELETE_TYPE_MAINTENANCE,
                        hourlyUsageRetentionDatetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly),
                    ));
                },
                [
                    Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                    Query::limit(100),
                    Query::greaterThanEqual('accessedAt', DatabaseDateTime::format($before30days)),
                    Query::orderAsc('teamInternalId'),
                ]
            );

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $console,
                type: DELETE_TYPE_MAINTENANCE,
                hourlyUsageRetentionDatetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly),
            ));

            $this->notifyDeleteConnections($publisherForDeletes);
            $this->renewCertificates($dbForPlatform, $publisherForCertificates);
            $this->notifyDeleteCache($cacheRetention, $publisherForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $publisherForDeletes);
            $this->notifyDeleteCSVExports($publisherForDeletes);
        };

        if ($type === 'loop') {
            Console::info('Setting loop start time to ' . $next->format("Y-m-d H:i:s.v") . '. Delaying for ' . $delay . ' seconds.');

            Console::loop(function () use ($action) {
                $action();
            }, $interval, $delay);
        } elseif ($type === 'trigger') {
            $action();
        }
    }

    private function notifyDeleteConnections(DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_REALTIME,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -60),
        ));
    }

    private function notifyDeleteCSVExports(DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(type: DELETE_TYPE_CSV_EXPORTS));
    }

    private function renewCertificates(Database $dbForPlatform, Certificate $publisherForCertificate): void
    {
        $time = DatabaseDateTime::now();

        $certificates = $dbForPlatform->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);

        if (\count($certificates) === 0) {
            Console::info("[{$time}] No certificates for renewal.");
            return;
        }

        Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $appRegion = System::getEnv('_APP_REGION', 'default');

        foreach ($certificates as $certificate) {
            $domain = $certificate->getAttribute('domain');
            $rule = $isMd5 ?
                $dbForPlatform->getDocument('rules', md5($domain)) :
                    $dbForPlatform->findOne('rules', [
                        Query::equal('domain', [$domain]),
                        Query::limit(1)
                    ]);

            if ($rule->isEmpty() || $rule->getAttribute('region') !== $appRegion) {
                continue;
            }

            $publisherForCertificate->enqueue(new \Appwrite\Event\Message\Certificate(
                project: new Document([
                    '$id' => $rule->getAttribute('projectId', ''),
                    '$sequence' => $rule->getAttribute('projectInternalId', 0),
                ]),
                domain: new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]),
                action: \Appwrite\Event\Certificate::ACTION_GENERATION,
            ));
        }
    }

    private function notifyDeleteCache($interval, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_CACHE_BY_TIMESTAMP,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval),
        ));
    }

    private function notifyDeleteSchedules($interval, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_SCHEDULES,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval),
        ));
    }
}
