<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Sessions;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteUserSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/:userId/sessions/:sessionId')
            ->desc('Delete user session')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].sessions.[sessionId].delete')
            ->label('scope', ['users.write', 'sessions.write'])
            ->label('audits.event', 'session.delete')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'sessions',
                name: 'deleteSession',
                description: '/docs/references/users/delete-user-session.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('sessionId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Session ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $sessionId, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $session = $dbForProject->getDocument('sessions', $sessionId);

        if ($session->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        if ($user->getId() !== $session->getAttribute('userId')) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $dbForProject->deleteDocument('sessions', $session->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $sessionId)
            ->setPayload($response->output($session, Response::MODEL_SESSION));

        $response->noContent();
    }
}
