<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Spotify;

use Appwrite\Auth\OAuth2\Spotify;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'spotify';
    }

    public static function getProviderClass(): string
    {
        return Spotify::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Spotify';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Spotify';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SPOTIFY;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '6ec271000000000000000000009beace';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'db068a000000000000000000008b5b9f';
    }
}
