<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Queue\Broker\Pool as BrokerPool;
use Utopia\Queue\Queue;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Histogram;

/**
 * ScheduleMessages
 *
 * Sends scheduled messages at the moment they were scheduled for, and never
 * before: this task takes no lead time, so a message is handed over once it
 * is due rather than a tick early.
 */
class ScheduleMessages extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks

    /** A message must not go out early, so the dispatch gets no lead time. */
    public const ENQUEUE_LOOKAHEAD = 0; // seconds

    public const ENQUEUE_LOOKBACK = 300; // seconds

    protected BrokerPool $publisherMessaging;

    private ?MessagingPublisher $publisherForMessaging = null;

    private ?Histogram $enqueueDelay = null;

    private ?Telemetry $telemetry = null;

    private ?Scheduler $scheduler = null;

    private ?ScheduleSource $source = null;

    private ?Database $dbForPlatform = null;

    public function __construct()
    {
        $this
            ->desc('Execute messages scheduled in Appwrite')
            ->inject('publisherMessaging')
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

    public function action(BrokerPool $publisherMessaging, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry, Group $pools): never
    {
        Console::title('Message scheduler V1');
        Console::success(APP_NAME . ' message scheduler v1 has started');

        $this->setup($publisherMessaging, $telemetry);
        $this->start($dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $this->listen();

        // The loop returns only when something asks it to stop, and nothing
        // here does. Exiting non-zero has the supervisor restart the task
        // rather than leaving a live process that schedules nothing.
        Console::error('Scheduler loop returned unexpectedly');
        exit(1);
    }

    /**
     * Wire the publisher and telemetry. Safe to call once before start().
     */
    public function setup(BrokerPool $publisherMessaging, Telemetry $telemetry): void
    {
        $this->publisherMessaging = $publisherMessaging;
        $this->enqueueDelay = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');
        $this->telemetry = $telemetry;
    }

    /**
     * Build the scheduler and load the schedules once. Combined mode runs this
     * serially per resource type so they do not contend for the shared
     * console and cache pools.
     */
    public function start(Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): void
    {
        $source = new ScheduleSource(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            resource: fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument(self::getCollectionId(), $schedule['resourceId']),
            entry: fn (array $schedule): Entry => new Entry($this->trigger($schedule), $schedule),
            recency: self::UPDATE_TIMER * 3,
        );

        $scheduler = new Scheduler(
            source: $source,
            // The claim carries leadership and the committed window in one
            // record, so replicas elect a single dispatcher and a replacement
            // resumes coverage where its predecessor stopped.
            store: new ScheduleStore($pools, 'utopia-schedule-' . self::getName()),
            interval: self::ENQUEUE_TIMER,
            sync: self::UPDATE_TIMER,
            // A change feed cannot report a hard delete, so a full snapshot
            // still runs periodically to converge removals.
            relist: self::UPDATE_TIMER * 30,
            lookahead: self::ENQUEUE_LOOKAHEAD,
            lookback: self::ENQUEUE_LOOKBACK,
            lease: 60,
            telemetry: $this->telemetry ?? new \Utopia\Telemetry\Adapter\None(),
            onError: function (\Throwable $error): void {
                // A failed sync leaves the last good view dispatching: stale
                // schedules beat a stopped scheduler.
                Console::error('Failed to reconcile message schedules: ' . $error->getMessage());
            },
        );

        $scheduler->reconcile();

        $this->source = $source;
        $this->scheduler = $scheduler;
        $this->dbForPlatform = $dbForPlatform;
    }

    /**
     * How many active schedules the last full snapshot reported.
     */
    public function scheduleCount(): int
    {
        return $this->source?->snapshotted() ?? 0;
    }

    /**
     * Run the loop. Blocks, so combined mode gives each task its own
     * coroutine.
     */
    public function listen(): void
    {
        $scheduler = $this->scheduler;
        $dbForPlatform = $this->dbForPlatform;

        if ($scheduler === null || $dbForPlatform === null) {
            throw new \LogicException('start() must run before listen()');
        }

        Console::success('Starting message scheduler at ' . DateTime::now());

        $scheduler->run(function (array $occurrences) use ($dbForPlatform): void {
            $this->dispatch($occurrences, $dbForPlatform);
        });
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, Database $dbForPlatform): void
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            \go(function () use ($schedule, $occurrence, $dbForPlatform) {
                try {
                    $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                    $this->publisher()->enqueue(new MessagingMessage(
                        type: MESSAGE_SEND_TYPE_EXTERNAL,
                        project: $schedule['project'],
                        messageId: $schedule['resourceId'],
                    ));

                    // The row is the retirement record: dropping it is what
                    // stops a later snapshot from listing this message again.
                    $dbForPlatform->deleteDocument('schedules', $schedule['$id']);

                    $this->enqueueDelay?->record(
                        \time() - $occurrence->due->getTimestamp(),
                        ['resourceType' => self::getSupportedResource()]
                    );
                } catch (\Throwable $th) {
                    Console::error("Failed to enqueue scheduled message {$schedule['resourceId']}: {$th->getMessage()}");
                }
            });
        }
    }

    private function publisher(): MessagingPublisher
    {
        return $this->publisherForMessaging ??= new MessagingPublisher(
            $this->publisherMessaging,
            new Queue(System::getEnv('_APP_MESSAGING_QUEUE_NAME', Event::MESSAGING_QUEUE_NAME))
        );
    }
}
