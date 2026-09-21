<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dailymotion;

use Appwrite\Auth\OAuth2\Dailymotion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'dailymotion';
    }

    public static function getProviderClass(): string
    {
        return Dailymotion::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Dailymotion';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Dailymotion';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DAILYMOTION;
    }

    public static function getClientIdParamName(): string
    {
        return 'apiKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'apiSecret';
    }

    public static function getClientIdName(): string
    {
        return 'API Key';
    }

    public static function getClientIdExample(): string
    {
        return '07a9000000000000067f';
    }

    public static function getClientSecretName(): string
    {
        return 'API Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'a399a90000000000000000000000000000d90639';
    }
}
