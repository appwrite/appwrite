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
        // Auth\OAuth2\Gitlab reads the endpoint out of a JSON-encoded appSecret; no setEndpoint().
        $browserEndpoint = System::getEnv('_APP_VCS_GITLAB_BROWSER_ENDPOINT', System::getEnv('_APP_VCS_GITLAB_ENDPOINT', 'https://gitlab.com'));

        return new OAuth2Gitlab(
            System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''),
            \json_encode([
                'clientSecret' => System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
                'endpoint' => $browserEndpoint,
            ]),
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
