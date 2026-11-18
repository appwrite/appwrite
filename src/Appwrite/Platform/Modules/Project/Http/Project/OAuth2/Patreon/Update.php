<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Patreon;

use Appwrite\Auth\OAuth2\Patreon;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'patreon';
    }

    public static function getProviderClass(): string
    {
        return Patreon::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Patreon';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Patreon';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_PATREON;
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
