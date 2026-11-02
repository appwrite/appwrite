<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Recovery\OTP;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRecoveryOTP';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/recovery/otp')
            ->desc('Create password recovery (OTP)')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].recovery.[tokenId].create')
            ->label('audits.event', 'recovery.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'recovery',
                name: 'createRecoveryOTP',
                description: '/docs/references/account/create-recovery-otp.md',
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
            ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be sent with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('locale')
            ->inject('publisherForMails')
            ->inject('queueForEvents')
            ->inject('proofForCode')
            ->inject('authorization')
            ->inject('apiKey');
    }
}
