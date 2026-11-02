<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Status;

use Appwrite\Event\Event;
use Appwrite\Event\Realtime;
use Appwrite\Messaging\Adapter\Mqtt;
use Appwrite\Platform\Action;
use Appwrite\Presences\State as PresenceState;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/status')
            ->desc('Update status')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.status')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updateStatus',
                description: '/docs/references/account/update-status.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('store')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('project')
            ->inject('mqtt')
            ->inject('queueForRealtime')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents,
        Store $store,
        bool $domainVerification,
        ?string $cookieDomain,
        Document $project,
        Mqtt $mqtt,
        Realtime $queueForRealtime,
        Context $usage,
    ): void {
        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['status' => false]));

        try {
            $mqtt->revoke($project->getId(), $user->getId());
        } catch (\Throwable $error) {
            Console::error('Failed to revoke MQTT connections: ' . $error->getMessage());
        }

        (new PresenceState())->deleteForUser($dbForProject, $project, $user, $queueForRealtime, $usage);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_ACCOUNT));

        if (!$domainVerification) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        $secure = $request->getProtocol() === 'https';
        $response
            ->addCookie($store->getKey() . '_legacy', '', \time() - 3600, '/', $cookieDomain, $secure, true, null)
            ->addCookie($store->getKey(), '', \time() - 3600, '/', $cookieDomain, $secure, true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
