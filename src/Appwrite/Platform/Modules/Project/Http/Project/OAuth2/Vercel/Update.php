<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Vercel;

use Appwrite\Auth\OAuth2\Vercel;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'vercel';
    }

    public static function getProviderClass(): string
    {
        return Vercel::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Vercel';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Vercel';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_VERCEL;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'oac_xxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    }
}
