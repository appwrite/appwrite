<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\VK;

use Appwrite\Auth\OAuth2\VK;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'vk';
    }

    public static function getProviderClass(): string
    {
        return VK::class;
    }

    public static function getProviderLabel(): string
    {
        return 'VK';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2VK';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_VK;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '00000000000000000000000000000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '00000000000000000000000000000000';
    }
}
