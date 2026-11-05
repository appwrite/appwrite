<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Clients;

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
        return 'deleteClient';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/clients/:clientId')
            ->desc('Delete client')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'client.delete')
            ->label('audits.resource', 'client/{request.clientId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'clients',
                name: 'deleteClient',
                description: <<<EOT
                Delete the account switching client of the current device. Every session the client holds is signed out, the client is removed, and both the client and session cookies are cleared. Use the string 'current' to reference the device's own client.
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
            ->param('clientId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Client ID. Use the string \'current\' to reference the current device client.', true, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('client')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('authorization')
            ->inject('mqtt')
            ->callback($this->action(...));
    }

    public function action(
        ?string $clientId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        ?Document $client,
        bool $domainVerification,
        ?string $cookieDomain,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Authorization $authorization,
        Mqtt $mqtt,
    ): void {
        if ($client === null) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        if ($clientId !== 'current' && $clientId !== $client->getId()) {
            throw new Exception(Exception::CLIENT_NOT_FOUND);
        }

        $protocol = $request->getProtocol();
        $sessions = $client->getAttribute('sessions', []);
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

        $authorization->skip(fn () => $dbForProject->deleteDocument('clients', $client->getId()));

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

        // Clear both the client cookie and the active session cookie: the device is fully signed out.
        $response
            ->addCookie($project->getClientKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($project->getClientKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->addCookie($project->getSessionKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, null)
            ->addCookie($project->getSessionKey(), '', \time() - 3600, '/', $cookieDomain, ('https' == $protocol), true, Config::getParam('cookieSamesite'));

        $queueForEvents->setParam('clientId', $client->getId());

        $response->noContent();
    }
}
