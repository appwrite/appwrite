<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Certificates\Certificates;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Schedule\Source\Chores;
use DateInterval;
use DateTime;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Schedule\Scheduler;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
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
            ->inject('certificateIssuer')
            ->inject('publisherForDeletes')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(string $type, Database $dbForPlatform, Document $console, Certificate $publisherForCertificates, Certificates $certificateIssuer, DeletePublisher $publisherForDeletes, Telemetry $telemetry): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        $interval = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'); // 1 day
        $usageStatsRetentionHourly = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_USAGE_HOURLY', '8640000'); //100 days
        $cacheRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day
        $jobInitTime = System::getEnv('_APP_MAINTENANCE_START_TIME', '00:00'); // (hour:minutes)

        // The next occurrence of the configured start time, which anchors the
        // grid. Occurrences are anchor + k x interval, so the run stays pinned
        // to that wall-clock time instead of drifting by however long each run
        // takes, the way sleeping for the interval did.
        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new \DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        if ($next->getTimestamp() <= $now->getTimestamp()) {
            $next->add(\DateInterval::createFromDateString('1 days'));
        }

        // One entry per chore, not one closure over all of them. They share a
        // cadence but nothing else: each enqueues to a different queue, and a
        // failure in one says nothing about the next. Running them as one
        // closure meant the first throw skipped every chore behind it -- and,
        // under Console::loop, ended the loop for good.
        $chores = [
            'projects' => fn () => $this->notifyProjects($dbForPlatform, $publisherForDeletes, $usageStatsRetentionHourly),
            'console' => fn () => $this->notifyConsole($console, $publisherForDeletes, $usageStatsRetentionHourly),
            'connections' => fn () => $this->notifyDeleteConnections($publisherForDeletes),
            'certificates' => fn () => $this->renewCertificates($dbForPlatform, $publisherForCertificates, $certificateIssuer),
            'cache' => fn () => $this->notifyDeleteCache($cacheRetention, $publisherForDeletes),
            'schedules' => fn () => $this->notifyDeleteSchedules($schedulesDeletionRetention, $publisherForDeletes),
            'csv-exports' => fn () => $this->notifyDeleteCSVExports($publisherForDeletes),
        ];

        if ($type === 'trigger') {
            foreach ($chores as $id => $chore) {
                $this->chore($id, $chore);
            }

            return;
        }

        Console::info('Anchoring the maintenance grid to ' . $next->format('Y-m-d H:i:s.v') . ', every ' . $interval . ' seconds.');

        $scheduler = new Scheduler(
            source: new Chores(\array_keys($chores), $interval, \DateTimeImmutable::createFromMutable($next)),
            // The chore set is a constant, so there is nothing to re-read.
            syncSeconds: $interval,
            // Deliberately left at its default, unlike the usage sweep: a
            // maintenance run enqueues a delete for every project in the
            // region, and replaying a missed day on every restart would be
            // worse than skipping it -- which is what the old loop did.
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Console::error('maintenance: reconcile failed: ' . $error->getMessage());
            },
        );

        $scheduler->run(function (array $due) use ($chores): null {
            foreach ($due as $occurrence) {
                $chore = $chores[$occurrence->id] ?? null;

                if ($chore === null) {
                    continue;
                }

                $this->chore($occurrence->id, $chore);
            }

            return null;
        });

        // run() returns only if something stopped the loop. Say so loudly: a
        // scheduler that has stopped scheduling still looks alive and Ready.
        Console::error('maintenance: scheduler loop returned, scheduling has stopped');
    }

    /**
     * Run one chore, containing its failure.
     *
     * Nothing dispatched may throw: the Scheduler records a dispatch error and
     * rethrows it, which ends run() -- and the process then stays alive and
     * idle, never exiting, so restartPolicy never fires.
     *
     * @param callable(): void $chore
     */
    private function chore(string $id, callable $chore): void
    {
        $time = DatabaseDateTime::now();

        try {
            $chore();
        } catch (\Throwable $th) {
            Console::error("[{$time}] maintenance chore '{$id}' failed, retrying next interval: " . $th->getMessage());
        }
    }

    private function notifyProjects(Database $dbForPlatform, DeletePublisher $publisherForDeletes, int $usageStatsRetentionHourly): void
    {
        // Iterate through project only if it was accessed in last 30 days
        $dateInterval = DateInterval::createFromDateString('30 days');
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
                Query::greaterThanEqual('accessedAt', DatabaseDateTime::format($before30days)),
                Query::orderAsc('$sequence'), // accessedAt Can be updated during iteration
                Query::limit(1000),
            ]
        );
    }

    private function notifyConsole(Document $console, DeletePublisher $publisherForDeletes, int $usageStatsRetentionHourly): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $console,
            type: DELETE_TYPE_MAINTENANCE,
            hourlyUsageRetentionDatetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $usageStatsRetentionHourly),
        ));
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

    private function renewCertificates(Database $dbForPlatform, Certificate $publisherForCertificate, Certificates $certificateIssuer): void
    {
        $time = DatabaseDateTime::now();

        $documents = $dbForPlatform->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);

        if (\count($documents) === 0) {
            Console::info("[{$time}] No certificates for renewal.");
            return;
        }

        Console::info("[{$time}] Found " . \count($documents) . " certificates for renewal, scheduling jobs.");

        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $appRegion = System::getEnv('_APP_REGION', 'default');

        foreach ($documents as $certificate) {
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

            // Respect the operator opt-out. If Appwrite would not auto-issue this
            // subdomain today, it must not auto-renew it either. Keep the owner
            // gate so custom-domain renewals are never skipped.
            if ($rule->getAttribute('owner') === 'Appwrite' && !$certificateIssuer->isAutoIssueEnabled($rule)) {
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
