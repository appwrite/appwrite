<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Sessions\OAuth2;

use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Account\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createOAuth2Session';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/account/sessions/oauth2/:provider')
            ->desc('Create OAuth2 session')
            ->groups(['api', 'account'])
            ->label('error', APP_VIEWS_DIR . '/general/error.phtml')
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'sessions',
                name: 'createOAuth2Session',
                description: '/docs/references/account/create-session-oauth2.md',
                type: MethodType::WEBAUTH,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_MOVED_PERMANENTLY,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::HTML,
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') === 'enabled' ? false : [APP_SDK_PLATFORM_SERVER],
            ))
            ->label('abuse-limit', 50)
            ->label('abuse-key', 'ip:{ip}');
    }
}
