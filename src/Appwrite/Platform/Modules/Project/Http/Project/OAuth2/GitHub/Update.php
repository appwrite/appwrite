<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\GitHub;

use Appwrite\Auth\OAuth2\Github;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'github';
    }

    public static function getProviderClass(): string
    {
        return Github::class;
    }

    public static function getProviderLabel(): string
    {
        return 'GitHub';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2GitHub';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_GITHUB;
    }

    public static function getClientIdName(): string
    {
        return 'OAuth 2 app Client ID, or App ID';
    }

    public static function getClientIdExample(): string
    {
        return 'e4d87900000000540733';
    }

    public static function getClientIdHint(): string
    {
        return 'Example of wrong value: 370006';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '5e07c00000000000000000000000000000198bcc';
    }
}
