<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Cloudflare;

use Appwrite\Auth\OAuth2\Cloudflare;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'cloudflare';
    }

    public static function getProviderClass(): string
    {
        return Cloudflare::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Cloudflare';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Cloudflare';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_CLOUDFLARE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '4b866000000000000000000000c9e4e2';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'cfoc_5Q6YRl0000000000000000000000000000000000003d214f';
    }
}
