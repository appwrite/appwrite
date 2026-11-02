<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Email;

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
        return 'updateEmail';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/account/email')
            ->desc('Update email')
            ->groups(['api', 'account'])
            ->label('event', 'users.[userId].update.email')
            ->label('scope', 'account')
            ->label('audits.event', 'user.update')
            ->label('audits.resource', 'user/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'updateEmail',
                description: '/docs/references/account/update-email.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ));
    }
}
