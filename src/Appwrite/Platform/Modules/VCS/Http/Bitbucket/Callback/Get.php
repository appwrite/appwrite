<?php

namespace Appwrite\Platform\Modules\VCS\Http\Bitbucket\Callback;

use Appwrite\Auth\OAuth2;
use Appwrite\Auth\OAuth2\Bitbucket as OAuth2Bitbucket;
use Appwrite\Platform\Modules\VCS\Http\Callback\Base;
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

    protected function createOAuth2(string $callback): OAuth2
    {
        return new OAuth2Bitbucket(
            System::getEnv('_APP_VCS_BITBUCKET_CLIENT_ID', ''),
            System::getEnv('_APP_VCS_BITBUCKET_CLIENT_SECRET', ''),
            $callback
        );
    }
}
