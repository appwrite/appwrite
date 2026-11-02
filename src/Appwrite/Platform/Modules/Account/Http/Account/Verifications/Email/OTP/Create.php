<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\OTP;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailVerificationOTP';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/email/otp')
            ->desc('Create email verification (OTP)')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'createEmailVerificationOTP',
                description: '/docs/references/account/create-email-verification-otp.md',
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
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be sent with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('proofForCode')
            ->inject('authorization');
    }
}
