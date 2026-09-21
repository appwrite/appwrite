<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Autodesk;

use Appwrite\Auth\OAuth2\Autodesk;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'autodesk';
    }

    public static function getProviderClass(): string
    {
        return Autodesk::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Autodesk';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Autodesk';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_AUTODESK;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '5zw90v00000000000000000000kVYXN7';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '7I000000000000MW';
    }
}
