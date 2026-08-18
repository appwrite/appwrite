<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Schedule\DatabaseSchedule;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Schedule\Occurrence;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Trigger\At;
use Utopia\Span\Span;
use Utopia\Telemetry\Adapter as Telemetry;

class ScheduleMessages extends Action
{
    public const UPDATE_TIMER = 3; // seconds between reconciliations

    public function __construct()
    {
        $this
            ->desc('Execute messages scheduled in Appwrite')
            ->inject('publisherForMessaging')
            ->inject('getIsResourceBlocked')
            ->inject('dbForPlatform')
            ->inject('getProjectDB')
            ->inject('telemetry')
            ->callback($this->action(...));
    }

    public static function getName(): string
    {
        return 'schedule-messages';
    }



    public function action(MessagingPublisher $publisherForMessaging, callable $getIsResourceBlocked, Database $dbForPlatform, callable $getProjectDB, Telemetry $telemetry): void
    {
        $source = new DatabaseSchedule(
            dbForPlatform: $dbForPlatform,
            getProjectDB: $getProjectDB,
            isResourceBlocked: $getIsResourceBlocked,
            resourceType: SCHEDULE_RESOURCE_TYPE_MESSAGE,
            collectionId: RESOURCE_TYPE_MESSAGES,
            resource: fn (Database $projectDB, array $schedule): Document => $projectDB->getDocument(RESOURCE_TYPE_MESSAGES, $schedule['resourceId']),
            entry: fn (array $schedule): Entry => new Entry(new At(new \DateTimeImmutable((string) $schedule['schedule'])), $schedule),
        );

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
            $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'accessedAt' => $now
            ]));
            $project->setAttribute('accessedAt', $now);
        }
    }

    /**
     * @param list<Occurrence> $occurrences
     */
    private function dispatch(array $occurrences, MessagingPublisher $publisherForMessaging, Database $dbForPlatform): null
    {
        foreach ($occurrences as $occurrence) {
            $schedule = $occurrence->payload;

            Span::init('schedule.messages.enqueue');
            $error = null;

            try {
                Span::add('project.id', $schedule['project']->getId());
                Span::add('schedule.id', $schedule['$id'] ?? '');

                $this->updateProjectAccess($schedule['project'], $dbForPlatform);

                $publisherForMessaging->enqueue(new MessagingMessage(
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
