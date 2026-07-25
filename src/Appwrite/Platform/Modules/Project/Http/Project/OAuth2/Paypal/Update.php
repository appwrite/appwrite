<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Paypal;

use Appwrite\Auth\OAuth2\Paypal;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'paypal';
    }

    public static function getProviderClass(): string
    {
        return Paypal::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Paypal';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Paypal';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_PAYPAL;
    }

    public static function getClientSecretParamName(): string
    {
        return 'secretKey';
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'AdhIEG7-000000000000-0000000000000000000000000000000-0000000000000000000000-2pyB';
    }

    public static function getClientSecretName(): string
    {
        return 'Secret Key 1 or Secret Key 2';
    }

    public static function getClientSecretExample(): string
    {
        return 'EH8KCXtew--000000000000000000000000000000000000000_C-1_5UP_000000000000000CB7KDp';
    }
}
