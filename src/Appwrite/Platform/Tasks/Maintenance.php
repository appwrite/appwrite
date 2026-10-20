<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Certificates\Certificates;
use Appwrite\Database\Provisioner;
use Appwrite\Event\Certificate as CertificateEvent;
use Appwrite\Event\Message\Certificate as CertificateMessage;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Cadence;
use Appwrite\Maintenance\Cohort;
use Appwrite\Maintenance\Outcome;
use Appwrite\Maintenance\Slot;
use Appwrite\Maintenance\Sweep;
use Appwrite\Maintenance\Ticker;
use DateInterval;
use DateTime;
use DateTimeZone;
use Swoole\Coroutine\WaitGroup;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Schedule\Clock;
use Utopia\Schedule\Clock\System as SystemClock;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Validator\Text;
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
            ->param('cohort', '', new WhiteList(['', Cohort::Active->value, Cohort::Inactive->value]), 'Run only this project cohort, without the system jobs.', optional: true)
            ->param('project', '', new Text(36), 'Run only this project, without the system jobs.', optional: true)
            ->inject('dbForPlatform')
            ->inject('console')
            ->inject('publisherForCertificates')
            ->inject('certificateIssuer')
            ->inject('publisherForDeletes')
            ->inject('databaseFactory')
            ->callback($this->action(...));
    }

    public function action(string $type, string $cohort, string $project, Database $dbForPlatform, Document $console, Certificate $publisherForCertificates, Certificates $certificateIssuer, DeletePublisher $publisherForDeletes, Provisioner $databaseFactory): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        $interval = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'); // 1 day
        $cacheRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day
        $jobInitTime = System::getEnv('_APP_MAINTENANCE_START_TIME', '00:00'); // (hour:minutes)

        $now = new DateTime();
        $now->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $next = new DateTime($now->format("Y-m-d $jobInitTime"));
        $next->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $delay = $next->getTimestamp() - $now->getTimestamp();

        /**
         * If time passed for the target day.
         */
        if ($delay <= 0) {
            $next->add(DateInterval::createFromDateString('1 days'));
            $delay = $next->getTimestamp() - $now->getTimestamp();
        }

        $action = function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $dbForPlatform, $console, $publisherForDeletes, $publisherForCertificates, $certificateIssuer): void {
            $time = DatabaseDateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $console,
                type: DELETE_TYPE_MAINTENANCE,
            ));

            $this->notifyDeleteConnections($publisherForDeletes);
            $this->renewCertificates($dbForPlatform, $publisherForCertificates, $certificateIssuer);
            $this->notifyDeleteCache($cacheRetention, $publisherForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $publisherForDeletes);
            $this->notifyDeleteCSVExports($publisherForDeletes);
        };

        // Sweep skips filters on its database, so it must not share the instance the system jobs use.
        $dbForSweep = $databaseFactory->platform(metadata: [
            'host' => \gethostname(),
            'project' => 'console',
        ]);
        $clock = new SystemClock();
        $region = System::getEnv('_APP_REGION', 'default');
        $sweep = new Sweep($dbForSweep, $publisherForDeletes, $clock, $region);

        if ($type === 'loop') {
            $inactiveInterval = (int) System::getEnv('_APP_MAINTENANCE_INACTIVE_INTERVAL', '604800');
            $cadence = Cadence::fromIntervals($interval, $inactiveInterval);
            $anchor = Cadence::anchor($jobInitTime, new DateTimeZone(date_default_timezone_get()));

            \go(function () use ($sweep, $clock, $cadence, $anchor): void {
                $this->loopCohorts($sweep, $clock, $cadence, $anchor);
            });

            Console::info('Setting loop start time to ' . $next->format('Y-m-d H:i:s.v') . '. Delaying for ' . $delay . ' seconds.');

            Console::loop(function () use ($action): void {
                $action();
            }, $interval, $delay);
        } elseif ($type === 'trigger') {
            $cutoff = Sweep::cutoff($clock);

            if ($cohort === '' && $project === '') {
                $this->sweep($sweep, Cohort::Active, Slot::full(), $cutoff);
                $action();

                return;
            }

            if ($project !== '') {
                $document = $dbForPlatform->getDocument('projects', $project, [Query::select(['region'])]);
                if ($document->isEmpty()) {
                    Console::warning("Project {$project} does not exist");
                } elseif ($document->getAttribute('region') !== $region) {
                    Console::warning("Project {$project} is in region {$document->getAttribute('region')}, not {$region}");
                }
            }

            $cohorts = $cohort === '' ? [Cohort::Active, Cohort::Inactive] : [Cohort::from($cohort)];

            foreach ($cohorts as $selected) {
                $this->sweep($sweep, $selected, Slot::full(), $cutoff, project: $project ?: null);
            }
        }
    }

    /**
     * Runs both cohorts on every tick boundary, anchor + k * tick. A boundary missed while sweeps overran is skipped,
     * not caught up.
     */
    private function loopCohorts(Sweep $sweep, Clock $clock, Cadence $cadence, int $anchor): never
    {
        $tick = $cadence->tick;
        $margin = \max(1, \min(60, \intdiv($tick, 10)));
        $ticker = new Ticker($cadence, $anchor, $clock);

        while (true) {
            $next = $ticker->next();
            $boundary = $next->boundary;

            if ($next->skipped > 0) {
                Console::warning("Maintenance skipped {$next->skipped} tick boundaries after an overrun");
            }

            $cutoff = Sweep::cutoff($clock);
            $runs = [
                [Cohort::Active, Slot::at($boundary, $anchor, $tick, $cadence->activeSlots), null],
                [Cohort::Inactive, Slot::at($boundary, $anchor, $tick, $cadence->inactiveSlots), $boundary + $tick - $margin],
            ];

            foreach ($runs as [$cohort, $slot, $deadline]) {
                try {
                    $this->sweep($sweep, $cohort, $slot, $cutoff, $deadline, skipped: $next->skipped);
                } catch (\Throwable $error) {
                    Console::error("Maintenance {$cohort->value} cohort failed: {$error->getMessage()}");
                }
            }
        }
    }

    /**
     * Spans are per coroutine and Span has no child API, so the sweep runs in its own coroutine to leave the
     * caller's span current.
     */
    private function sweep(Sweep $sweep, Cohort $cohort, Slot $slot, string $cutoff, ?int $deadline = null, ?string $project = null, int $skipped = 0): void
    {
        $failure = null;
        $group = new WaitGroup();
        $group->add();

        \go(function () use ($sweep, $cohort, $slot, $cutoff, $deadline, $project, $skipped, $group, &$failure): void {
            $span = Span::init('maintenance.cohort')
                ->set('maintenance.cohort', $cohort->value)
                ->set('maintenance.slot', $slot->index)
                ->set('maintenance.slots', $slot->count)
                ->set('maintenance.cycle', $slot->cycle)
                ->set('maintenance.skipped', $skipped);

            try {
                $outcome = $sweep->run($cohort, $slot, $cutoff, $deadline, $project);

                $span
                    ->set('maintenance.reads', $outcome->reads)
                    ->set('maintenance.enqueued', $outcome->enqueued)
                    ->set('maintenance.waited', $outcome->waited)
                    ->set('maintenance.dropped', $outcome->dropped)
                    ->set('maintenance.truncated', $outcome->truncated)
                    ->finish();

                $this->report($outcome);
            } catch (\Throwable $error) {
                $span->finish(error: $error);
                $failure = $error;
            } finally {
                $group->done();
            }
        });

        $group->wait();

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function report(Outcome $outcome): void
    {
        $dropped = match (true) {
            $outcome->dropped > 0 => "dropped {$outcome->dropped} blocks",
            $outcome->truncated => 'dropped the remaining pages',
            default => 'dropped nothing',
        };
        $message = "Maintenance {$outcome->cohort->value} cohort: slot {$outcome->slot->index}/{$outcome->slot->count}, {$outcome->reads} reads, {$outcome->enqueued} enqueued, waited {$outcome->waited}s, {$dropped}";

        if ($outcome->truncated) {
            Console::warning($message);
        } else {
            Console::info($message);
        }
    }

    private function notifyDeleteConnections(DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_REALTIME,
            datetime: DatabaseDateTime::addSeconds(new DateTime(), -60),
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

        Console::info("[{$time}] Found " . \count($documents) . ' certificates for renewal, scheduling jobs.');

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

            $publisherForCertificate->enqueue(new CertificateMessage(
                project: new Document([
                    '$id' => $rule->getAttribute('projectId', ''),
                    '$sequence' => $rule->getAttribute('projectInternalId', 0),
                ]),
                domain: new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]),
                action: CertificateEvent::ACTION_GENERATION,
            ));
        }
    }

    private function notifyDeleteCache(int $interval, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_CACHE_BY_TIMESTAMP,
            datetime: DatabaseDateTime::addSeconds(new DateTime(), -1 * $interval),
        ));
    }

    private function notifyDeleteSchedules(int $interval, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_SCHEDULES,
            datetime: DatabaseDateTime::addSeconds(new DateTime(), -1 * $interval),
        ));
    }
}
