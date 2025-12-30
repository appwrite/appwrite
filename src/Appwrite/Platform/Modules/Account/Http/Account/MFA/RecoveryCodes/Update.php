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
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateMFARecoveryCodes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/mfa/recovery-codes')
            ->desc('Update MFA recovery codes (regenerate)')
            ->groups(['api', 'account', 'mfaProtected'])
            ->label('event', 'users.[userId].update.mfa')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMfaRecoveryCodes',
                    description: '/docs/references/account/update-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.updateMFARecoveryCodes',
                    ),
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'updateMFARecoveryCodes',
                    description: '/docs/references/account/update-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->inject('dbForProject')
            ->inject('response')
            ->inject('user')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        Database $dbForProject,
        Response $response,
        Document $user,
        Event $queueForEvents
    ): void {
        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    }
}
