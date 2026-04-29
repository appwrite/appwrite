<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Facebook;

use Appwrite\Auth\OAuth2\Facebook;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'facebook';
    }

    public static function getProviderClass(): string
    {
        return Facebook::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Facebook';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Facebook';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_FACEBOOK;
    }

    public static function getClientIdParamName(): string
    {
        return 'appId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'appSecret';
    }

    public static function getClientIdName(): string
    {
        return 'App ID';
    }

    public static function getClientIdExample(): string
    {
        return '260600000007694';
    }

    public static function getClientSecretName(): string
    {
        return 'App Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '2d0b2800000000000000000000d38af4';
    }
}
