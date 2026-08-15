<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitlab\Callback;

use Appwrite\Platform\Modules\VCS\Http\Callback\Base;
use Utopia\Auth\OAuth2\Provider;
use Utopia\Auth\OAuth2\Providers\Gitlab as OAuth2Gitlab;
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

    protected function createOAuth2(string $callback): Provider
    {
        return new OAuth2Gitlab(
            System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''),
            \json_encode([
                'clientSecret' => System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
                'endpoint' => 'https://gitlab.com',
            ]),
            $callback
        );
    }
}
