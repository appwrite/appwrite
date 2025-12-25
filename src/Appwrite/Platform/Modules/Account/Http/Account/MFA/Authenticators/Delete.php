<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\WhiteList;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteMFAAuthenticator';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/mfa/authenticators/:type')
            ->desc('Delete authenticator')
            ->groups(['api', 'account', 'mfaProtected'])
            ->label('event', 'users.[userId].delete.mfa')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'deleteMfaAuthenticator',
                    description: '/docs/references/account/delete-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_NOCONTENT,
                            model: Response::MODEL_NONE,
                        )
                    ],
                    contentType: ContentType::NONE,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.deleteMFAAuthenticator',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'deleteMFAAuthenticator',
                    description: '/docs/references/account/delete-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_NOCONTENT,
                            model: Response::MODEL_NONE,
                        )
                    ],
                    contentType: ContentType::NONE
                )
            ])
            ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $type,
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $authenticator = (match ($type) {
            Type::TOTP => TOTP::getAuthenticatorFromUser($user),
            default => null
        });

        if (!$authenticator) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->noContent();
    }
}
