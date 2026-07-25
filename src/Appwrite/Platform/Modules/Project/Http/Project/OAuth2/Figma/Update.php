<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Figma;

use Appwrite\Auth\OAuth2\Figma;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'figma';
    }

    public static function getProviderClass(): string
    {
        return Figma::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Figma';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Figma';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_FIGMA;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'byay5H0000000000VtiI40';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'yEpOYn0000000000000000004iIsU5';
    }
}
