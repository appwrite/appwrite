<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Devices;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
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
            ->label('audits.resource', 'device/{request.deviceId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'devices',
                name: 'deleteDevice',
                description: <<<EOT
                Delete the account switching device for the current browser. Every session the device holds is signed out, the device is removed, and both the device and session cookies are cleared. Use the string 'current' to reference the current browser's device.
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
            ->inject('device')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        ?string $deviceId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        ?Document $device,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        Mqtt $mqtt,
    ): void {
        if ($device === null) {
            throw new Exception(Exception::DEVICE_NOT_FOUND);
        }

        if ($deviceId !== 'current' && $deviceId !== $device->getId()) {
            throw new Exception(Exception::DEVICE_NOT_FOUND);
        }

        $protocol = $request->getProtocol();
        $sessions = $device->getAttribute('sessions', []);
        $userIds = [];

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
        }

        $authorization->skip(fn () => $dbForProject->deleteDocument('devices', $device->getId()));

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

        $queueForEvents->setParam('deviceId', $device->getId());

        $response->noContent();
    }
}
