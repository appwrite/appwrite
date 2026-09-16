<?php

namespace Appwrite\Platform\Modules\S3\Http\Shutdown;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Functions\EventProcessor;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Http\Route;
use Utopia\Platform\Action;

/**
 * Fans queued S3 events out to realtime, event-triggered functions and
 * webhooks. Mirrors the `api` group's events shutdown hook in
 * app/controllers/shared/api.php, which does not run for the `s3` group.
 */
class Events extends Action
{
    public static function getName(): string
    {
        return 'shutdownEvents';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_SHUTDOWN)
            ->groups(['s3'])
            ->desc('S3 gateway event fan-out')
            ->inject('route')
            ->inject('response')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForFunctions')
            ->inject('queueForWebhooks')
            ->inject('queueForRealtime')
            ->inject('dbForProject')
            ->inject('eventProcessor')
            ->callback($this->action(...));
    }

    public function action(Route $route, Response $response, Document $project, Event $queueForEvents, FunctionPublisher $publisherForFunctions, Event $queueForWebhooks, Realtime $queueForRealtime, Database $dbForProject, EventProcessor $eventProcessor): void
    {
        if (empty($queueForEvents->getEvent())) {
            return;
        }

        if (empty($queueForEvents->getPayload())) {
            $queueForEvents->setPayload($response->getPayload());
        }

        // Get project and function/webhook events (cached)
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        // Generate events for this operation
        $generatedEvents = Event::generateEvents(
            $queueForEvents->getEvent(),
            $queueForEvents->getParams()
        );

        $allowedOnConsole = !empty(\array_intersect($route->getGroups(), Realtime::CONSOLE_ALLOWLIST));
        if ($project->getId() !== 'console' || $allowedOnConsole) {
            $queueForRealtime
                ->from($queueForEvents)
                ->trigger();
        }

        // Only trigger functions if there are matching function events
        if (!empty($functionsEvents)) {
            foreach ($generatedEvents as $event) {
                if (isset($functionsEvents[$event])) {
                    $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                        event: $queueForEvents->getEvent(),
                        params: $queueForEvents->getParams(),
                        project: $queueForEvents->getProject(),
                        user: $queueForEvents->getUser(),
                        userId: $queueForEvents->getUserId(),
                        payload: $queueForEvents->getPayload(),
                        platform: $queueForEvents->getPlatform(),
                    ));
                    break;
                }
            }
        }

        // Only trigger webhooks if there are matching webhook events
        if (!empty($webhooksEvents)) {
            foreach ($generatedEvents as $event) {
                if (isset($webhooksEvents[$event])) {
                    $queueForWebhooks
                        ->from($queueForEvents)
                        ->trigger();
                    break;
                }
            }
        }
    }
}
