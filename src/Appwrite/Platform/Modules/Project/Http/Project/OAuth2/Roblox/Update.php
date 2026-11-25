<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Roblox;

use Appwrite\Auth\OAuth2\Roblox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'roblox';
    }

    public static function getProviderClass(): string
    {
        return Roblox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Roblox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Roblox';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ROBLOX;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '1234567890123456789';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'RBX-0000000000000000000000000000000000000000000000000000000000000000000000';
    }
}
