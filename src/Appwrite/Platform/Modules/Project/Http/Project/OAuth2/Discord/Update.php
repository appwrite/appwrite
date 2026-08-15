<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord;

use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;
use Utopia\Auth\OAuth2\Providers\Discord;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'discord';
    }

    public static function getProviderClass(): string
    {
        return Discord::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Discord';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Discord';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DISCORD;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '950722000000343754';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'YmPXnM000000000000000000002zFg5D';
    }
}
