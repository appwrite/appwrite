<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Linkedin;

use Appwrite\Auth\OAuth2\Linkedin;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'linkedin';
    }

    public static function getProviderClass(): string
    {
        return Linkedin::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Linkedin';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Linkedin';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_LINKEDIN;
    }

    public static function getClientSecretParamName(): string
    {
        return 'primaryClientSecret';
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '770000000000dv';
    }

    public static function getClientSecretName(): string
    {
        return 'Primary Client Secret or Secondary Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'WPL_AP1.2Bf0000000000000./HtlYw==';
    }
}
