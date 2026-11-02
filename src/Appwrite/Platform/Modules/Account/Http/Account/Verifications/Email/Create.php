<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/email')
            ->httpAlias('/v1/account/verification')
            ->desc('Create email verification')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'createEmailVerification',
                    description: '/docs/references/account/create-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON,
                ),
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'createVerification',
                    description: '/docs/references/account/create-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.createEmailVerification'
                    ),
                    public: false,
                )
            ])
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['redirectValidator']) // TODO add built-in confirm page
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('proofForToken')
            ->inject('authorization');
    }
}
