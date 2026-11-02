<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePhoneVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/verifications/phone')
            ->httpAlias('/v1/account/verification/phone')
            ->desc('Update phone verification (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'public')
            ->label('event', 'users.[userId].verification.[tokenId].update')
            ->label('audits.event', 'verification.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'updatePhoneVerification',
                description: '/docs/references/account/update-phone-verification.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{param-userId}');
    }
}
