<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\Verification;

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
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateUserEmailVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/verification')
            ->desc('Update email verification')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.verification')
            ->label('scope', 'users.write')
            ->label('audits.event', 'verification.update')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('audits.userId', '{request.userId}')
            ->label('sdk', new Method(
                namespace: 'users',
                group: 'users',
                name: 'updateEmailVerification',
                description: '/docs/references/users/update-user-email-verification.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ]
            ))
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('emailVerification', false, new Boolean(), 'User email verification status.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, bool $emailVerification, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['emailVerification' => $emailVerification]));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
