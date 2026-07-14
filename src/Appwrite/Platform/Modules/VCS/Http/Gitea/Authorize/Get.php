<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Authorize;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Platform\Modules\VCS\Http\Authorize\Base;
use Utopia\System\System;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGiteaAuthorize';
    }

    public static function getProvider(): string
    {
        return 'gitea';
    }

    public static function getProviderName(): string
    {
        return 'Gitea';
    }

    protected function createOAuth2(string $callback, array $state): OAuth2
    {
        $oauth2 = new OAuth2Gitea(
            System::getEnv('_APP_VCS_GITEA_CLIENT_ID', ''),
            System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET', ''),
            $callback,
            $state
        );

        // The login page is opened by the browser, which may reach Gitea on a
        // different host than the server-side API endpoint (e.g. Docker).
        $browserEndpoint = System::getEnv('_APP_VCS_GITEA_BROWSER_ENDPOINT', System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''));
        $oauth2->setEndpoint($browserEndpoint);

        return $oauth2;
    }
}
