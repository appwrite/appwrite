<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\PaypalSandbox;

use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Paypal\Update as PaypalUpdate;
use Utopia\Auth\OAuth2\Providers\PaypalSandbox;

class Update extends PaypalUpdate
{
    public static function getProviderId(): string
    {
        return 'paypalSandbox';
    }

    public static function getProviderClass(): string
    {
        return PaypalSandbox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'PaypalSandbox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2PaypalSandbox';
    }
}
