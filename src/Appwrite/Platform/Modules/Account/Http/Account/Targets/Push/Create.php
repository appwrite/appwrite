<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Targets\Push;

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
        return 'createPushTarget';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/targets/push')
            ->desc('Create push target')
            ->groups(['api', 'account'])
            ->label('scope', 'targets.write')
            ->label('audits.event', 'target.create')
            ->label('audits.resource', 'target/response.$id')
            ->label('event', 'users.[userId].targets.[targetId].create')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'pushTargets',
                name: 'createPushTarget',
                description: '/docs/references/account/create-push-target.md',
                auth: [AuthType::ADMIN, AuthType::SESSION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TARGET,
                    )
                ],
                contentType: ContentType::JSON
            ));
    }
}
