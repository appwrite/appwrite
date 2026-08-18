<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Store\Redis as ClaimStore;
use Utopia\Schedule\Trigger\At;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMessages extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks
    public const ENQUEUE_LOOKAHEAD = 0; // no lead time: a message must not go out early
    public const ENQUEUE_LOOKBACK = 300; // seconds of missed runs a restart recovers

    public function __construct()
    {
        $this
            ->desc('Execute messages scheduled in Appwrite')
            ->inject('publisherForMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->inject('pools')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-messages';
    }

    public static function getSupportedResource(): string
    {
        return SCHEDULE_RESOURCE_TYPE_MESSAGE;
    }

    public static function getCollectionId(): string
    {
        return RESOURCE_TYPE_MESSAGES;
    }

    public function action(MessagingPublisher $publisherForMessaging, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        ($this->boot($publisherForMessaging, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools))();

        Span::init('schedule.messages.stopped');
        Span::current()?->finish(error: new \RuntimeException('Scheduler loop returned'));

        exit(1);
    }

    /**
     * Load the schedules and return the loop that dispatches them, so the
     * combined task can load all three before running any.
     */
    public function boot(MessagingPublisher $publisher, Telemetry $telemetry, Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): \Closure
    {
        Span::init('schedule.messages.boot');

        $source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            resource: fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument(self::getCollectionId(), $schedule['resourceId']),
            entry: fn (array $schedule): Entry => new Entry(new At(new \DateTimeImmutable((string) $schedule['schedule'])), $schedule),
        );

        $scheduler = new Scheduler(
            source: $source,
            store: new ClaimStore($pools->get('lock')->pop()->resource, 'utopia-schedule-' . self::getName()),
            tickSeconds: self::ENQUEUE_TIMER,
            syncSeconds: self::UPDATE_TIMER,
            leadSeconds: self::ENQUEUE_LOOKAHEAD,
            recoverSeconds: self::ENQUEUE_LOOKBACK,
            telemetry: $telemetry,
            onError: function (\Throwable $error): void {
                Span::init('schedule.messages.reconcile');
                Span::current()?->finish(error: $error);
            },
        );

        $scheduler->reconcile();

        Span::add('schedule.messages.loaded', $source->snapshotted());
        Span::current()?->finish();

        return fn (): null => $scheduler->run(fn (array $occurrences): null => $this->dispatch($occurrences, $publisher, $dbForPlatform));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, MessagingPublisher $publisher, Database $dbForPlatform): null
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.messages.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $publisher->enqueue(new MessagingMessage(
                    type: MESSAGE_SEND_TYPE_EXTERNAL,
                    project: $schedule['project'],
                    messageId: $schedule['resourceId'],
                ));

                // The row is the retirement record: dropping it is what stops
                // a later snapshot from listing this message again.
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
