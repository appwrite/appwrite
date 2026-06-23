<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\WordPress;

use Appwrite\Auth\OAuth2\WordPress;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'wordpress';
    }

    public static function getProviderClass(): string
    {
        return WordPress::class;
    }

    public static function getProviderLabel(): string
    {
        return 'WordPress';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2WordPress';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_WORDPRESS;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '130005';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'PlBfJS0000000000000000000000000000000000000000000000000000EdUZJk';
    }
}
