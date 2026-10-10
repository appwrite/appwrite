<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Naver;

use Appwrite\Auth\OAuth2\Naver;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'naver';
    }

    public static function getProviderClass(): string
    {
        return Naver::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Naver';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Naver';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_NAVER;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'jK8pQ0000000000000Zv';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '9f42A0000000000c1dEb';
    }
}
