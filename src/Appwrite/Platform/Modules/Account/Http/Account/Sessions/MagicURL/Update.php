<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\MagicURL;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Update extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateMagicURLSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/sessions/magic-url')
            ->desc('Update magic URL session')
            ->label('event', 'users.[userId].sessions.[sessionId].create')
            ->groups(['api', 'account', 'session'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'updateMagicURLSession',
                description: '/docs/references/account/create-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON,
                deprecated: new Deprecated(
                    since: '1.6.0',
                    replaceWith: 'account.createSession'
                ),
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['ip:{ip},userId:{param-userId}', 'projectId:{projectId},url:{url},userId:{param-userId}'])
            ->label('abuse-reset', [201]);
    }
}
