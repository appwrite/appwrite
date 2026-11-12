<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices;

use Appwrite\Abuse\Limit;
use Appwrite\Auth\AuthDevice;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\Project;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteDevice';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/devices/:deviceId')
            ->desc('Delete device')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'device.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'deleteDevice',
                description: <<<EOT
                Delete the account switching device for the current browser. Every session the device holds is signed out, each firing its own session delete event, the device is removed, and both the device and session cookies are cleared. Use the string 'current' to reference the current browser's device.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->label('abuse', [Limit::attempts(['ip'], 100)])
            ->param('deviceId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Device ID. Use the string \'current\' to reference the device of the current browser.', true, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authDevice')
            ->inject('locale')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('queueForRealtime')
            ->inject('queueForWebhooks')
            ->inject('publisherForFunctions')
            ->inject('publisherForDeletes')
            ->inject('eventProcessor')
            ->inject('authorization')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        ?string $deviceId,
        Request $request,
        Response $response,
        Project $project,
        Database $dbForProject,
        ?Document $authDevice,
        Locale $locale,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        Realtime $queueForRealtime,
        Event $queueForWebhooks,
        FunctionPublisher $publisherForFunctions,
        DeletePublisher $publisherForDeletes,
        EventProcessor $eventProcessor,
        Authorization $authorization,
        Mqtt $mqtt,
    ): void {
        if ($authDevice === null) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        if ($deviceId !== 'current' && $deviceId !== $authDevice->getId()) {
            throw new Exception(Exception::USER_DEVICE_NOT_FOUND);
        }

        $protocol = $request->getProtocol();
        $activeSessionId = $authDevice->getAttribute('activeSessionId', '');
        $sessions = $authDevice->getAttribute('sessions', []);
        $names = AuthDevice::userNames($dbForProject, $authorization, $sessions);
        $userIds = [];

        // Each session is signed out the way deleteSession does it, so the sessions-delete event
        // reaches webhooks, functions and realtime for every account, not just the active one.
        // The request carries a single event, so each one is dispatched here, mirroring the
        // api shutdown hook.
        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        foreach ($sessions as $session) {
            /** @var Document $session */
            $authorization->skip(fn () => $dbForProject->deleteDocument('sessions', $session->getId()));

            $userId = $session->getAttribute('userId', '');
            if (!empty($userId)) {
                $userIds[$userId] = true;
            }

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $queueForEvents->getProject(),
                type: DELETE_TYPE_SESSION_TARGETS,
                document: $session,
            ));

            $session
                ->setAttribute('current', $session->getId() === $activeSessionId)
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode', '')), $locale->getText('locale.country.unknown')))
                ->setAttribute('userName', $names[$userId] ?? '');

            $event = (clone $queueForEvents)
                ->setEvent('users.[userId].sessions.[sessionId].delete')
                ->setParam('userId', $userId)
                ->setParam('sessionId', $session->getId())
                ->setPayload($response->output($session, Response::MODEL_SESSION), sensitive: ['secret', 'providerAccessToken', 'providerRefreshToken']);

            $generatedEvents = Event::generateEvents($event->getEvent(), $event->getParams(), $event->getContext('database'));

            if ($project->getId() !== 'console' || \in_array('account', Realtime::CONSOLE_ALLOWLIST)) {
                (clone $queueForRealtime)
                    ->from($event)
                    ->trigger();
            }

            foreach ($generatedEvents as $generatedEvent) {
                if (isset($functionsEvents[$generatedEvent])) {
                    $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                        event: $event->getEvent(),
                        params: $event->getParams(),
                        project: $event->getProject(),
                        user: $event->getUser(),
                        userId: $event->getUserId(),
                        payload: $event->getPayload(),
                        platform: $event->getPlatform(),
                        database: $event->getContext('database'),
                    ));
                    break;
                }
            }

            foreach ($generatedEvents as $generatedEvent) {
                if (isset($webhooksEvents[$generatedEvent])) {
                    (clone $queueForWebhooks)
                        ->from($event)
                        ->trigger();
                    break;
                }
            }
        }

        $authorization->skip(fn () => $dbForProject->deleteDocument('devices', $authDevice->getId()));

        foreach (array_keys($userIds) as $userId) {
            $dbForProject->purgeCachedDocument('users', $userId);

            try {
                $mqtt->revoke($project->getId(), $userId);
            } catch (\Throwable $error) {
                Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
            }
        }

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        // Clear both the device cookie and the active session cookie: the device is fully signed out.
        $response
            ->addCookie($project->getDeviceKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($project->getDeviceKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->addCookie($project->getSessionKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($project->getSessionKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));

        $queueForEvents->setParam('deviceId', $authDevice->getId());

        $response->noContent();
    }
}
