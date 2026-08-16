<?php

namespace Appwrite\Platform\Modules\VCS\Http\Bitbucket\Callback;

use Appwrite\Platform\Modules\VCS\Http\Callback\Base;
use Utopia\Auth\OAuth2\Provider;
use Utopia\Auth\OAuth2\Providers\Bitbucket as OAuth2Bitbucket;
use Utopia\System\System;

class Get extends Base
{
    public static function getName()
    {
        return 'getVCSBitbucketCallback';
    }

    public static function getProvider(): string
    {
        return 'bitbucket';
    }

    public static function getProviderName(): string
    {
        return 'Bitbucket';
    }

    protected function createOAuth2(string $callback): Provider
    {
        return new OAuth2Bitbucket(
            System::getEnv('_APP_VCS_BITBUCKET_CLIENT_ID', ''),
            System::getEnv('_APP_VCS_BITBUCKET_CLIENT_SECRET', ''),
            $callback
        );
    }
}
