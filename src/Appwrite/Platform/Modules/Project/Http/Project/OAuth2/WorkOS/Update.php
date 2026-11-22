<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\WorkOS;

use Appwrite\Auth\OAuth2\WorkOS;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'workos';
    }

    public static function getProviderClass(): string
    {
        return WorkOS::class;
    }

    public static function getProviderLabel(): string
    {
        return 'WorkOS';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2WorkOS';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_WORKOS;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'client_01H0000000000000000000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'wos0000000000000000000000000000000000000000000000000000000000000';
    }
}
