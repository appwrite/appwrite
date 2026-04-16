<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Authenticators;

use Appwrite\Auth\MFA\Challenge;
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
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateMFAAuthenticator';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/mfa/authenticators/:type')
            ->desc('Update authenticator (confirmation)')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.mfa')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMfaAuthenticator',
                    description: '/docs/references/account/update-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_USER,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.updateMFAAuthenticator',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMFAAuthenticator',
                    description: '/docs/references/account/update-mfa-authenticator.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_USER,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.')
            ->param('otp', '', new Text(256), 'Valid verification token.')
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $type,
        string $otp,
        Response $response,
        Document $user,
        Document $session,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $authenticator = (match ($type) {
            Type::TOTP => TOTP::getAuthenticatorFromUser($user),
            default => null
        });

        if ($authenticator === null) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        if ($authenticator->getAttribute('verified')) {
            throw new Exception(Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED);
        }

        $success = (match ($type) {
            Type::TOTP => Challenge\TOTP::verify($user, $otp),
            default => false
        });

        if (!$success) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authenticator->setAttribute('verified', true);

        $dbForProject->updateDocument('authenticators', $authenticator->getId(), $authenticator);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $factors = $session->getAttribute('factors', []);
        $factors[] = $type;
        $factors = \array_values(\array_unique($factors));

        $session->setAttribute('factors', $factors);
        $dbForProject->updateDocument('sessions', $session->getId(), $session);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
