<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord;

use Appwrite\Auth\OAuth2\Discord;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

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

    public static function getPromptValues(): array
    {
        return ['none', 'consent'];
    }

    public static function getPromptLimit(): int
    {
        return 1;
    }

    public static function getPromptDescription(): string
    {
        return 'Array with at most one Discord OAuth2 prompt value. "none" means: skip the authorization screen for users who already authorized the app with the requested scopes. "consent" means: ask users who already authorized the app to approve it again. Pass an empty array to use the Discord default.';
    }
}
