<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Schedule\Source;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMessages extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations

    /** @var callable(string, int, callable): mixed */
    private $locks;

    public function __construct()
    {
        $this
            ->desc('Execute messages scheduled in Appwrite')
            ->inject('publisherForMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-messages';
    }

    public function action(MessagingPublisher $publisherForMessaging, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, callable $locks): void
    {
        $this->locks = $locks;

        $source = new Source\Messages($dbForPlatform, $getProjectDB, $getIsResourceBlocked);

        $scheduler = new Scheduler(
            source: $source,
            syncSeconds: self::UPDATE_TIMER,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.messages.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisherForMessaging, $dbForPlatform));

        Span::init('schedule.messages.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return;
        }

        $accessedAt = $project->getAttribute('accessedAt', 0);
        if (DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -APP_PROJECT_ACCESS)) > $accessedAt) {
            $now = DateTime::now();

            // Concurrent occurrences each carry their own project snapshot, so
            // every one of them reads the same stale accessedAt and would write
            // it. The lock keeps that to one write, as the request path does.
            ($this->locks)(
                'lock:platform:projects:'.$project->getId().':accessedAt',
                APP_PROJECT_ACCESS,
                function () use ($dbForPlatform, $project, $now): void {
                    // updateDocument never uses cache, so skip the subqueries.
                    $dbForPlatform->skipFilters(
                        fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                            'accessedAt' => $now
                        ])),
                        APP_PROJECTS_SUBQUERIES
                    );
                }
            );

            $project->setAttribute('accessedAt', $now);
        }
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, MessagingPublisher $publisherForMessaging, Database $dbForPlatform): null
    {
        $batch = \count($occurrences);

        foreach (\array_values($occurrences) as $index => $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.messages.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');
                Span::add('message.id', (string) ($schedule['resourceId'] ?? ''));
                Span::add('occurrence.due', $occurrence->due->format('c'));
                Span::add('occurrence.late', \round(\microtime(true) - (float) $occurrence->due->format('U.u'), 3));
                Span::add('occurrence.batch', $batch);
                Span::add('occurrence.index', $index);

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $publisherForMessaging->enqueue(new MessagingMessage(
                    type: MESSAGE_SEND_TYPE_EXTERNAL,
                    project: $schedule['project'],
                    messageId: $schedule['resourceId'],
                ));

                $dbForPlatform->deleteDocument('schedules', $schedule['$id']);
            } catch (\Throwable $th) {
                $error = $th;
            } finally {
                Span::current()?->finish(error: $error);
            }
        }

        return null;
    }
}
