<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitlab\Callback;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Gitlab as OAuth2Gitlab;
use Appwrite\Platform\Modules\VCS\Http\Callback\Base;
use Utopia\System\System;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGitlabCallback';
    }

    public static function getProvider(): string
    {
        return 'gitlab';
    }

    public static function getProviderName(): string
    {
        return 'GitLab';
    }

    protected function createOAuth2(string $callback): OAuth2
    {
        // See Authorize/Get.php -- Auth\OAuth2\Gitlab reads the endpoint out
        // of a JSON-encoded appSecret. Token exchange is a server-to-server
        // call, so this uses the API endpoint, not the browser one.
        return new OAuth2Gitlab(
            System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''),
            \json_encode([
                'clientSecret' => System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
                'endpoint' => System::getEnv('_APP_VCS_GITLAB_ENDPOINT', 'https://gitlab.com'),
            ]),
            $callback
        );
    }
}
