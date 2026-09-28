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
        return '0d6a00000000000000000000000000000000000000000000000000000000f31c';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '7b1e00000000000000000000000000000000000000000000000000000000ac95';
    }
}
