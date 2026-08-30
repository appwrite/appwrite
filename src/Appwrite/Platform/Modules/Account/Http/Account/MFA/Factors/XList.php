<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Factors;

use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listMFAFactors';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/mfa/factors')
            ->desc('List factors')
            ->groups(['api', 'account', 'mfa'])
            ->label('scope', 'account')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'listMfaFactors',
                    description: '/docs/references/account/list-mfa-factors.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_FACTORS,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.listMFAFactors',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'listMFAFactors',
                    description: '/docs/references/account/list-mfa-factors.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_OK,
                            model: Response::MODEL_MFA_FACTORS,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->inject('response')
            ->inject('user')
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Response $response, Document $user, Document $project): void
    {
        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
        $recoveryCodeEnabled = \is_array($mfaRecoveryCodes) && \count($mfaRecoveryCodes) > 0;

        $totp = TOTP::getAuthenticatorFromUser($user);

        $mfaFactors = $project->getAttribute('auths', [])['mfaFactors'] ?? [];

        $factors = new Document([
            Type::TOTP => ($mfaFactors['totp'] ?? true) && $totp !== null && $totp->getAttribute('verified', false),
            Type::EMAIL => ($mfaFactors['email'] ?? true) && $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false),
            Type::PHONE => ($mfaFactors['phone'] ?? true) && $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false),
            Type::RECOVERY_CODE => $recoveryCodeEnabled,
            Type::CUSTOM => $mfaFactors['custom'] ?? false
        ]);

        $response->dynamic($factors, Response::MODEL_MFA_FACTORS);
    }
}
