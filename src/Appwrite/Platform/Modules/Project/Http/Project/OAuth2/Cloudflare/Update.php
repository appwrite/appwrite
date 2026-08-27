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
        return '8c33c3da9e8f392k71m1f9dc1a190cb3707ad27ba4d19bff45c900e6dfet1f4a';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '2d106b111a390d9692ab9a8a295ac05668632b17bbb342d149209aaaaa100000';
    }
}
