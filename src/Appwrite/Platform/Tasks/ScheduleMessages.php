<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Pools\Group;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;
use Utopia\Telemetry\Adapter as Telemetry;
use Utopia\Telemetry\Histogram;

/**
 * Sends scheduled messages at the moment they were scheduled for, and never
 * before: this task takes no lead time, so a message is handed over once it is
 * due rather than a tick early.
 */
class ScheduleMessages extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations
    public const ENQUEUE_TIMER = 4; // seconds between ticks
    public const ENQUEUE_LOOKAHEAD = 0; // seconds of lead time

    private ?Schedules $schedules = null;

    private ?MessagingPublisher $publisher = null;

    private ?Histogram $enqueueDelay = null;

    private ?Database $dbForPlatform = null;

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
        Console::title('Message scheduler V1');
        Console::success(APP_NAME . ' message scheduler v1 has started');

        $this->start($publisherForMessaging, $telemetry, $dbForPlatform, $getProjectDB, $getIsResourceBlocked, $pools);
        $this->listen();

        // Nothing here stops the loop, so a return means the supervisor
        // should restart the task rather than leave it scheduling nothing.
        Console::error('Scheduler loop returned unexpectedly');
        exit(1);
    }

    public function start(MessagingPublisher $publisherForMessaging, Telemetry $telemetry, Database $dbForPlatform, callable $getProjectDB, callable $getIsResourceBlocked, Group $pools): void
    {
        $this->publisher = $publisherForMessaging;
        $this->enqueueDelay = $telemetry->createHistogram('task.schedule.enqueue_delay', 's');
        $this->dbForPlatform = $dbForPlatform;
        $this->schedules = new Schedules(
            name: self::getName(),
            resourceType: self::getSupportedResource(),
            collectionId: self::getCollectionId(),
            sync: self::UPDATE_TIMER,
            tick: self::ENQUEUE_TIMER,
            lookahead: self::ENQUEUE_LOOKAHEAD,
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            pools: $pools,
            trigger: $this->trigger(...),
            telemetry: $telemetry,
        );
        $this->schedules->load();
    }

    public function scheduleCount(): int
    {
        return $this->schedules?->count() ?? 0;
    }

    public function listen(): void
    {
        $schedules = $this->schedules ?? throw new \LogicException('start() must run before listen()');

        Console::success('Starting message scheduler at ' . DateTime::now());

        $schedules->run($this->dispatch(...));
    }

    protected function updateProjectAccess(Document $project, Database $dbForPlatform): void
    {
        ScheduleSource::touchProject($project, $dbForPlatform);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    public function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences): void
    {
        $dbForPlatform = $this->dbForPlatform ?? throw new \LogicException('start() must run before dispatch()');

        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            \go(function () use ($schedule, $occurrence, $dbForPlatform): void {
                try {
                    $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                    $this->publisher?->enqueue(new MessagingMessage(
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
}
