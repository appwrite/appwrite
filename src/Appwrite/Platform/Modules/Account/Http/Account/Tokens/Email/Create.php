<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Email;

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
        return 'createEmailToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/email')
            ->desc('Create email token (OTP)')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-otp')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createEmailToken',
                description: '/docs/references/account/create-token-email.md',
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
            ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}']);
    }
}
