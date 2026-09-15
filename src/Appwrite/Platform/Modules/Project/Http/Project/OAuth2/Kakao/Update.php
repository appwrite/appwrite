<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Kakao;

use Appwrite\Auth\OAuth2\Kakao;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'kakao';
    }

    public static function getProviderClass(): string
    {
        return Kakao::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Kakao';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Kakao';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_KAKAO;
    }

    public static function getClientIdName(): string
    {
        return 'REST API key';
    }

    public static function getClientIdExample(): string
    {
        return '839ff5000000000000000000013206de';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'jLNVOK00000000000000000000yJebea';
    }

    public static function getClientSecretHint(): string
    {
        return 'Generate it under Kakao Login > Security and set its status to enabled';
    }
}
