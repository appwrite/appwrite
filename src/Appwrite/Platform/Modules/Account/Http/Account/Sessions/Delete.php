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

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteSession';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/account/sessions/:sessionId')
            ->desc('Delete session')
            ->groups(['api', 'account', 'mfa'])
            ->label('scope', 'account')
            ->label('impersonation', 'allow')
            ->label('event', 'users.[userId].sessions.[sessionId].delete')
            ->label('audits.event', 'session.delete')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'deleteSession',
                description: '/docs/references/account/delete-session.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE
            ))
            ->label('abuse-limit', 100)
            ->param('sessionId', 'current', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Session ID. Use the string \'current\' to delete the current device session.', true, ['dbForProject'])
            ->inject('requestTimestamp')
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('store')
            ->inject('proofForToken')
            ->inject('domainVerification')
            ->inject('cookieDomain')
            ->inject('session')
            ->inject('project')
            ->inject('mqtt');
    }
}
