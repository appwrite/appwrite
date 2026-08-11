<?php

namespace Appwrite\Platform\Modules\Users\Http\Users\MFA\Factors;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
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

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listUserMFAFactors';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/users/:userId/mfa/factors')
            ->desc('List factors')
            ->groups(['api', 'users'])
            ->label('scope', 'users.read')
            ->label('usage.metric', 'users.{scope}.requests.read')
            ->label('sdk', [
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'listMfaFactors',
                    description: '/docs/references/users/list-mfa-factors.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_FACTORS,
                        )
                    ],
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'users.listMFAFactors',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'users',
                    group: 'mfa',
                    name: 'listMFAFactors',
                    description: '/docs/references/users/list-mfa-factors.md',
                    auth: [AuthType::ADMIN, AuthType::KEY],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_FACTORS,
                        )
                    ]
                )
            ])
            ->param('userId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'User ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(string $userId, Response $response, Database $dbForProject, Document $project): void
    {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $totp = TOTP::getAuthenticatorFromUser($user);

        $mfaFactors = $project->getAttribute('auths', [])['mfaFactors'] ?? [];

        $factors = new Document([
            Type::TOTP => ($mfaFactors['totp'] ?? true) && $totp !== null && $totp->getAttribute('verified', false),
            Type::EMAIL => ($mfaFactors['email'] ?? true) && $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false),
            Type::PHONE => ($mfaFactors['phone'] ?? true) && $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false),
            Type::CUSTOM => $mfaFactors['custom'] ?? false
        ]);

        $response->dynamic($factors, Response::MODEL_MFA_FACTORS);
    }
}
