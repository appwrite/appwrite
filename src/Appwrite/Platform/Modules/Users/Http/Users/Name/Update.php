<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Name;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserName';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/name')
            ->desc('Update name')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.name')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updateName',
                description: '/docs/references/users/update-user-name.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('name', '', new Text(128, 0), 'User name. Max length: 128 chars.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $name, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('name', $name);

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['name' => $user->getAttribute('name')]));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
