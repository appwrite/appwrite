<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\MFA\Authenticators;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteUserMFAAuthenticator';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/users/:userId/mfa/authenticators/:type')
            ->desc('Delete authenticator')
            ->groups(['api', 'users'])
            ->label('event', 'users.[userId].delete.mfa')
            ->label('scope', 'users.write')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{request.userId}')
            ->label('audits.userId', '{request.userId}')
            ->label('usage.metric', 'users.{scope}.requests.update')
            ->label('sdk', [
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'deleteMfaAuthenticator',
                    description: '/docs/references/users/delete-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_NOCONTENT,
                            model: Response::MODEL_NONE,
                        )
                    ],
                    contentType: ContentType::NONE,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'users.deleteMFAAuthenticator',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'deleteMFAAuthenticator',
                    description: '/docs/references/users/delete-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_NOCONTENT,
                            model: Response::MODEL_NONE,
                        )
                    ],
                    contentType: ContentType::NONE
                )
            ])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.', enum: new Enum(name: 'AuthenticatorType'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(string $userId, string $type, Response $response, Database $dbForProject, Event $queueForEvents): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $authenticator = TOTP::getAuthenticatorFromUser($user);

        if ($authenticator === null) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->noContent();
    }
}
