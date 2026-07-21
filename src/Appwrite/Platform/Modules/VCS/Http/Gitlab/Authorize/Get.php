<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitlab\Authorize;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Gitlab as OAuth2Gitlab;
use Appwrite\Platform\Modules\VCS\Http\Authorize\Base;
use Utopia\System\System;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGitlabAuthorize';
    }

    public static function getProvider(): string
    {
        return 'gitlab';
    }

    public static function getProviderName(): string
    {
        return 'GitLab';
    }

    protected function createOAuth2(string $callback, array $state): OAuth2
    {
        return new OAuth2Gitlab(
            System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''),
            System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
            $callback,
            $state,
            // api is required for webhook/merge-request-note writes; no finer-grained scope covers both.
            [
                'read_user',
                'api',
            ]
        );
    }
}
