<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPhoneVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/phone')
            ->httpAlias('/v1/account/verification/phone')
            ->desc('Create phone verification')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'account')
            ->label('auth.type', 'phone')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'createPhoneVerification',
                description: '/docs/references/account/create-phone-verification.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['url:{url},userId:{userId}', 'url:{url},ip:{ip}']);
    }
}
