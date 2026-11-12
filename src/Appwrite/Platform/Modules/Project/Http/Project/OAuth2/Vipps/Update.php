<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Vipps;

use Appwrite\Auth\OAuth2\Vipps;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'vipps';
    }

    public static function getProviderClass(): string
    {
        return Vipps::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Vipps MobilePay';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Vipps';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_VIPPS;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '9a3a4b3e-0000-0000-0000-0f1a6d1c3b2e';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'Ay1jb000000000000000000000000000000Vx3k';
    }
}
