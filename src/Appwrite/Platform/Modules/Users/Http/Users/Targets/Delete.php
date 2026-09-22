<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Targets;

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
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteUserTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/:userId/targets/:targetId')
            ->desc('Delete user target')
            ->groups(['api', 'users'])
            ->label('audits.event', 'target.delete')
            ->label('audits.resource', 'target/{request.$targetId}')
            ->label('event', 'users.[userId].targets.[targetId].delete')
            ->label('scope', 'users.write')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'targets',
                name: 'deleteTarget',
                description: '/docs/references/users/delete-target.md',
                auth: [AuthType::KEY, AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Target ID.', false, ['dbForProject'])
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $targetId, Event $queueForEvents, DeletePublisher $publisherForDeletes, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $dbForProject->deleteDocument('targets', $target->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $queueForEvents->getProject(),
            type: DELETE_TYPE_TARGET,
            document: $target,
        ));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response->noContent();
    }
}
