<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Name;

use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateName';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/name')
            ->desc('Update name')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.name')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updateName',
                description: '/docs/references/account/update-name.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents');
    }
}
