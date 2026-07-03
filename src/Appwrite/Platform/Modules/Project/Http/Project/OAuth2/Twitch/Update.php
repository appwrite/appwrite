<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Twitch;

use Appwrite\Auth\OAuth2\Twitch;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'twitch';
    }

    public static function getProviderClass(): string
    {
        return Twitch::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Twitch';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Twitch';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_TWITCH;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'vvi0in000000000000000000ikmt9p';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'pmapue000000000000000000zylw3v';
    }
}
