<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/sessions/:sessionId')
            ->desc('Update session')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].sessions.[sessionId].update')
            ->label('audits.event', 'session.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'updateSession',
                description: '/docs/references/account/update-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_SESSION,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->param('sessionId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Session ID. Use the string \'current\' to update the current device session.', true, ['dbForProject'])
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('session')
            ->inject('clientForOAuth2');
    }
}
