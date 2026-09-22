<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Appwrite;

use Appwrite\Auth\OAuth2\Appwrite;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'appwrite';
    }

    public static function getProviderClass(): string
    {
        return Appwrite::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Appwrite';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Appwrite';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_APPWRITE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '6a42000000000000b5a0';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'b86afd000000000000000000000000000000000000000000000000000ced5f93';
    }
}
