<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Linear;

use Appwrite\Auth\OAuth2\Linear;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'linear';
    }

    public static function getProviderClass(): string
    {
        return Linear::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Linear';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Linear';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_LINEAR;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '5e1c0000000000000000000000000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '9f8e7d6c5b4a00000000000000000000';
    }
}
