<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Alert as AlertMessage;
use Appwrite\Event\Publisher\Alert as AlertPublisher;
use Appwrite\Schedule\Source;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Span\Span;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Enqueues every enabled monitor in the region for evaluation on its
 * interval, from the rules' rows in `schedules`. The rule is measured and
 * compared in the `alerts` worker.
 */
class ScheduleAlerts extends Action
{
    public static function getName(): string
    {
        return 'schedule-alerts';
    }

    public function __construct()
    {
        $this
            ->desc('Schedule monitor evaluations')
            ->inject('publisherForAlerts')
            ->inject('dbForPlatform')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public function action(AlertPublisher $publisherForAlerts, Database $dbForPlatform, Telemetry $telemetry): void
    {
        $source = new Source\Alerts(
            $dbForPlatform,
            fn (array $schedules): array => $this->intervals($schedules, $dbForPlatform),
        );

        $scheduler = new Scheduler(
            source: $source,
            syncSeconds: \min(30, $this->interval()),
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.alerts.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForAlerts));

        Span::init('schedule.alerts.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    /**
     * Seconds between two evaluations of each rule in one page of its
     * schedules, keyed by schedule ID. Every rule shares `_APP_ALERTS_INTERVAL`;
     * an edition that sets the interval per organization overrides this and
     * reads the page's `teamInternalId`s (in each schedule's `data`) in one query.
     *
     * @param list<Document> $schedules
     * @return array<string, int>
     */
    protected function intervals(array $schedules, Database $dbForPlatform): array
    {
        $interval = $this->interval();

        $intervals = [];
        foreach ($schedules as $schedule) {
            $intervals[$schedule->getId()] = $interval;
        }

        return $intervals;
    }

    /**
     * The default interval, in seconds; at least one.
     */
    protected function interval(): int
    {
        return \max(1, (int) (System::getEnv('_APP_ALERTS_INTERVAL', '') ?: 60));
    }

    /**
     * Nothing here may throw: an exception ends the scheduler loop. A failed
     * enqueue costs that rule one evaluation.
     *
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, AlertPublisher $publisherForAlerts): null
    {
        foreach ($occurrences as $occurrence) {
            Span::init('schedule.alerts.enqueue');
            $error = null;

            try {
                Span::add('monitor.id', $occurrence->id);
                Span::add('project.id', $occurrence->payload['project']->getId());
                Span::add('occurrence.due', $occurrence->due->format('c'));

                if ($publisherForAlerts->enqueue(new AlertMessage(
                    project: $occurrence->payload['project'],
                    monitorId: $occurrence->payload['monitorId'],
                )) === false) {
                    $error = new \RuntimeException('Failed to enqueue');
                }
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        }

        return null;
    }
}
