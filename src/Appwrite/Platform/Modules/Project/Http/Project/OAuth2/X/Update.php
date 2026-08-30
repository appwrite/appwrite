<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\X;

use Appwrite\Auth\OAuth2\X;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'x';
    }

    public static function getProviderClass(): string
    {
        return X::class;
    }

    public static function getProviderLabel(): string
    {
        return 'X';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2X';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_X;
    }

    public static function getClientIdParamName(): string
    {
        return 'customerKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'secretKey';
    }

    public static function getClientIdName(): string
    {
        return 'Customer Key';
    }

    public static function getClientIdExample(): string
    {
        return 'slzZV0000000000000NFLaWT';
    }

    public static function getClientSecretName(): string
    {
        return 'Secret Key';
    }

    public static function getClientSecretExample(): string
    {
        return 'tkEPkp00000000000000000000000000000000000000FTxbI9';
    }
}
