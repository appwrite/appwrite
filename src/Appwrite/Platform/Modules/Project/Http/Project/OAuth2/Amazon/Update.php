<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Amazon;

use Appwrite\Auth\OAuth2\Amazon;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'amazon';
    }

    public static function getProviderClass(): string
    {
        return Amazon::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Amazon';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Amazon';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_AMAZON;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'amzn1.application-oa2-client.87400c00000000000000000000063d5b2';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '79ffe4000000000000000000000000000000000000000000000000000002de55';
    }
}
