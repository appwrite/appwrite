<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\RecoveryCodes;

use Appwrite\Auth\MFA\Type;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createMFARecoveryCodes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/mfa/recovery-codes')
            ->desc('Create MFA recovery codes')
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
                    name: 'createMfaRecoveryCodes',
                    description: '/docs/references/account/create-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.createMFARecoveryCodes',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'createMFARecoveryCodes',
                    description: '/docs/references/account/create-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        User $user,
        ?Document $session,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        // Recovery codes outlive sessions and password resets, so once MFA is in force, issuing them
        // needs the same recent challenge as listing or regenerating them. Before that, they are the
        // first step of setting up MFA and there is no second factor to challenge. A request without a
        // session (an account key) has nothing that could have completed a challenge.
        if ($user->getMinimumFactors() > 1 && ($session === null || !$user->sessionChallengeRecent($session->getId()))) {
            throw new Exception(Exception::USER_CHALLENGE_REQUIRED);
        }

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (!empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_ALREADY_EXISTS);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), new Document(['mfaRecoveryCodes' => $mfaRecoveryCodes]));

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($document, Response::MODEL_MFA_RECOVERY_CODES), sensitive: ['recoveryCodes']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    }
}
