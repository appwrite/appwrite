<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Atlassian;

use Appwrite\Auth\OAuth2\Atlassian;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'atlassian';
    }

    public static function getProviderClass(): string
    {
        return Atlassian::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Atlassian';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Atlassian';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ATLASSIAN;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'uQ7mZ3s0000000000000000000000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'ATOA0000000000000000000000000000000000000000000000000000000000000000000000';
    }
}
