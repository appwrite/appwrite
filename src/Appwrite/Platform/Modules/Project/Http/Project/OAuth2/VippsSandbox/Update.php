<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\VippsSandbox;

use Appwrite\Auth\OAuth2\VippsSandbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Vipps\Update as VippsUpdate;

class Update extends VippsUpdate
{
    public static function getProviderId(): string
    {
        return 'vippsSandbox';
    }

    public static function getProviderClass(): string
    {
        return VippsSandbox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Vipps MobilePay Sandbox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2VippsSandbox';
    }
}
