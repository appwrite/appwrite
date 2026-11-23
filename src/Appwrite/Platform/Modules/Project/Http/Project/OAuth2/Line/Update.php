<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Line;

use Appwrite\Auth\OAuth2\Line;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'line';
    }

    public static function getProviderClass(): string
    {
        return Line::class;
    }

    public static function getProviderLabel(): string
    {
        return 'LINE';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Line';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_LINE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '1650000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'a1b2c3d4e5f600000000000000000000';
    }
}
