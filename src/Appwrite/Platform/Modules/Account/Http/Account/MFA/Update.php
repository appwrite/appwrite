<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Event\Event;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateMFA';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/mfa')
            ->desc('Update MFA')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.mfa')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'mfa',
                name: 'updateMFA',
                description: '/docs/references/account/update-mfa.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('mfa', null, new Boolean(), 'Enable or disable MFA.')
            ->inject('requestTimestamp')
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $mfa,
        ?\DateTime $requestTimestamp,
        Response $response,
        Document $user,
        Document $session,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $user->setAttribute('mfa', $mfa);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        if ($mfa) {
            $factors = $session->getAttribute('factors', []);
            $totp = TOTP::getAuthenticatorFromUser($user);
            if ($totp !== null && $totp->getAttribute('verified', false)) {
                $factors[] = Type::TOTP;
            }
            if ($user->getAttribute('email', false) && $user->getAttribute('emailVerification', false)) {
                $factors[] = Type::EMAIL;
            }
            if ($user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false)) {
                $factors[] = Type::PHONE;
            }
            $factors = \array_values(\array_unique($factors));

            $session->setAttribute('factors', $factors);
            $dbForProject->updateDocument('sessions', $session->getId(), $session);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    }
}
