<?php

namespace Appwrite\Platform\Modules\VCS\Http\Gitea\Callback;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Gitea as OAuth2Gitea;
use Appwrite\Platform\Modules\VCS\Http\Callback\Base;
use Utopia\System\System;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSGiteaCallback';
    }

    public static function getProvider(): string
    {
        return 'gitea';
    }

    public static function getProviderName(): string
    {
        return 'Gitea';
    }

    protected function createOAuth2(string $callback): OAuth2
    {
        $oauth2 = new OAuth2Gitea(
            System::getEnv('_APP_VCS_GITEA_CLIENT_ID', ''),
            System::getEnv('_APP_VCS_GITEA_CLIENT_SECRET', ''),
            $callback
        );

        $oauth2->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', ''));

        return $oauth2;
    }
}
