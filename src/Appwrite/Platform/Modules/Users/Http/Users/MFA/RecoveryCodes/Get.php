<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\MFA\RecoveryCodes;

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

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getUserMFARecoveryCodes';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/mfa/recovery-codes')
            ->desc('Get MFA recovery codes')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('usage.metric', 'users.{scope}.requests.read')
            ->label('sdk', [
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'getMfaRecoveryCodes',
                    description: '/docs/references/users/get-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'users.getMFARecoveryCodes',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'getMFARecoveryCodes',
                    description: '/docs/references/users/get-mfa-recovery-codes.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_RECOVERY_CODES,
                        )
                    ]
                )
            ])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $userId, Response $response, Database $dbForProject): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    }
}
