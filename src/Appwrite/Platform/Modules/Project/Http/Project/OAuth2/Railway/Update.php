<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Railway;

use Appwrite\Auth\OAuth2\Railway;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'railway';
    }

    public static function getProviderClass(): string
    {
        return Railway::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Railway';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Railway';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_RAILWAY;
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
