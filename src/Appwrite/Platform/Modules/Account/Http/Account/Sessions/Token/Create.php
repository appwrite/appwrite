<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Token;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/sessions/token')
            ->desc('Create session')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->groups(['api', 'account', 'session'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createSession',
                description: '/docs/references/account/create-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['ip:{ip},userId:{param-userId}', 'projectId:{projectId},url:{url},userId:{param-userId}'])
            ->label('abuse-reset', [201]);
    }
}
