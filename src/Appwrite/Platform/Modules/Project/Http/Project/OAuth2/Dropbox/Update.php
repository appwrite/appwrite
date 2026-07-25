<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dropbox;

use Appwrite\Auth\OAuth2\Dropbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'dropbox';
    }

    public static function getProviderClass(): string
    {
        return Dropbox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Dropbox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Dropbox';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DROPBOX;
    }

    public static function getClientIdParamName(): string
    {
        return 'appKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'appSecret';
    }

    public static function getClientIdName(): string
    {
        return 'App Key';
    }

    public static function getClientIdExample(): string
    {
        return 'jl000000000009t';
    }

    public static function getClientSecretName(): string
    {
        return 'App Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'g200000000000vw';
    }
}
