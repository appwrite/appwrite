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
        // Auth\OAuth2\Gitlab (shared with the "sign in with GitLab" account
        // flow) reads both the client secret and the endpoint out of a
        // JSON-encoded appSecret -- there's no separate setEndpoint(). The
        // login page is opened by the browser, which may reach a self-hosted
        // GitLab on a different host than the server-side API endpoint (e.g.
        // Docker); public gitlab.com defaults apply to both.
        $browserEndpoint = System::getEnv('_APP_VCS_GITLAB_BROWSER_ENDPOINT', System::getEnv('_APP_VCS_GITLAB_ENDPOINT', 'https://gitlab.com'));

        return new OAuth2Gitlab(
            System::getEnv('_APP_VCS_GITLAB_CLIENT_ID', ''),
            \json_encode([
                'clientSecret' => System::getEnv('_APP_VCS_GITLAB_CLIENT_SECRET', ''),
                'endpoint' => $browserEndpoint,
            ]),
            $callback,
            $state,
            // VCS-specific scopes -- the adapter's own default is just
            // read_user, enough for a plain "sign in with GitLab". `api`
            // is required for creating/updating repository webhooks and
            // merge request notes; GitLab has no finer-grained scope that
            // covers both.
            [
                'read_user',
                'api',
            ]
        );
    }
}
