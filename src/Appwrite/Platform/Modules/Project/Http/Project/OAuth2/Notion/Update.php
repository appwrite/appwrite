<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Notion;

use Appwrite\Auth\OAuth2\Notion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'notion';
    }

    public static function getProviderClass(): string
    {
        return Notion::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Notion';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Notion';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_NOTION;
    }

    public static function getClientIdParamName(): string
    {
        return 'oauthClientId';
    }

    public static function getClientSecretParamName(): string
    {
        return 'oauthClientSecret';
    }

    public static function getClientIdName(): string
    {
        return 'OAuth Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '341d8700-0000-0000-0000-000000446ee3';
    }

    public static function getClientSecretName(): string
    {
        return 'OAuth Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'secret_dLUr4b000000000000000000000000000000lFHAa9';
    }
}
