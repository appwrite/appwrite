<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\MFA;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Deprecated;
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
        return 'updateUserMFA';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/users/:userId/mfa')
            ->desc('Update MFA')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].update.mfa')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('usage.metric', 'users.{scope}.requests.update')
            ->label('sdk', [
                new Method(
                    namespace: 'users',
                    group: 'users',
                    name: 'updateMfa',
                    description: '/docs/references/users/update-user-mfa.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_USER,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'users.updateMFA',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'users',
                    group: 'users',
                    name: 'updateMFA',
                    description: '/docs/references/users/update-user-mfa.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_USER,
                        )
                    ]
                )
            ])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('mfa', null, new Boolean(), 'Enable or disable MFA.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, bool $mfa, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('mfa', $mfa);

        $user = $dbForProject->updateDocument('users', $user->getId(), new Document(['mfa' => $user->getAttribute('mfa')]));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    }
}
