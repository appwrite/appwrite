<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

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
        return 'create';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account')
            ->desc('Create account')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-password')
            ->label('audits.event', 'user.create')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('audits.userId', '{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'create',
                description: '/docs/references/account/create.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10);
    }
}
