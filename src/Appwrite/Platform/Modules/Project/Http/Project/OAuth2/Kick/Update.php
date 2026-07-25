<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Kick;

use Appwrite\Auth\OAuth2\Kick;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'kick';
    }

    public static function getProviderClass(): string
    {
        return Kick::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Kick';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Kick';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_KICK;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '01KQ7C00000000000001MFHS32';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '34ac5600000000000000000000000000000000000000000000000000e830c8b';
    }
}
