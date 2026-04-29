<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google;

use Appwrite\Auth\OAuth2\Google;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'google';
    }

    public static function getProviderClass(): string
    {
        return Google::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Google';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Google';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_GOOGLE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '120000000095-92ifjb00000000000000000000g7ijfb.apps.googleusercontent.com';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'GOCSPX-2k8gsR0000000000000000VNahJj';
    }
}
