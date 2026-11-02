<?php

namespace Appwrite\Platform\Modules\Account\Http\Account;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account')
            ->desc('Get account')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'account',
                name: 'get',
                description: '/docs/references/account/get.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_USER,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->inject('response')
            ->inject('targetUser')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        User $targetUser,
    ): void {
        if ($targetUser->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $response->dynamic($targetUser, Response::MODEL_ACCOUNT);
    }
}
