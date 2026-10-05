<?php

namespace Appwrite\Platform\Modules\Users\Http\Users;

use Appwrite\Deletes\Identities as DeleteIdentities;
use Appwrite\Deletes\Targets as DeleteTargets;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteUser';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/:userId')
            ->desc('Delete user')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].delete')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.delete')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'delete',
                description: '/docs/references/users/delete.md',
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
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(string $userId, Response $response, Database $dbForProject, Event $queueForEvents, DeletePublisher $publisherForDeletes): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        // clone user object to send to workers
        $clone = clone $user;

        $dbForProject->deleteDocument('users', $userId);
        DeleteIdentities::delete($dbForProject, Query::equal('userInternalId', [$user->getSequence()]));
        DeleteTargets::delete($dbForProject, Query::equal('userInternalId', [$user->getSequence()]));

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_DOCUMENT,
            document: $clone,
        ));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($clone, Response::MODEL_USER));

        $response->noContent();
    }
}
