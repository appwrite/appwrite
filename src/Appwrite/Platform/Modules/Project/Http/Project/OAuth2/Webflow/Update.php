<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Webflow;

use Appwrite\Auth\OAuth2\Webflow;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'webflow';
    }

    public static function getProviderClass(): string
    {
        return Webflow::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Webflow';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Webflow';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_WEBFLOW;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '8bb20000000000000000000000000000000000000000000000000000000040dd';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '59bf00000000000000000000000000000000000000000000000000000000fe59';
    }
}
