<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Podio;

use Appwrite\Auth\OAuth2\Podio;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'podio';
    }

    public static function getProviderClass(): string
    {
        return Podio::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Podio';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Podio';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_PODIO;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'appwrite-o0000000st-app';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'Rn247T0000000000000000000000000000000000000000000000000000W2zWTN';
    }
}
