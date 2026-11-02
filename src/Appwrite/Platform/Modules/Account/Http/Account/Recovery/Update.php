<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Recovery;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateRecovery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/recovery')
            ->desc('Update password recovery (confirmation)')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].recovery.[tokenId].update')
            ->label('audits.event', 'recovery.update')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'recovery',
                name: 'updateRecovery',
                description: '/docs/references/account/update-recovery.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{param-userId}');
    }
}
