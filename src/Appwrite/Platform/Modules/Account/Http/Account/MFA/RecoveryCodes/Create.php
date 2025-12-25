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
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $user,
        Database $dbForProject,
        Event $queueForEvents
    ): void {
        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (!empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_ALREADY_EXISTS);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    }
}
