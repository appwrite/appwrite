<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\Bulk;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSessions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/sessions')
            ->desc('Delete sessions')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].sessions.[sessionId].delete')
            ->label('audits.event', 'session.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'deleteSessions',
                description: '/docs/references/account/delete-sessions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->label('abuse-limit', 100);
    }
}
