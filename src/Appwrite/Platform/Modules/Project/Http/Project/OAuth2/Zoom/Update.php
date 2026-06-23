<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoom;

use Appwrite\Auth\OAuth2\Zoom;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'zoom';
    }

    public static function getProviderClass(): string
    {
        return Zoom::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Zoom';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Zoom';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ZOOM;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'QMAC00000000000000w0AQ';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'GAWsG4000000000000000000007U01ON';
    }
}
