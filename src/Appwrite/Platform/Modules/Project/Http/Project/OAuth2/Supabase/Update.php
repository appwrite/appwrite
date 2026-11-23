<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Supabase;

use Appwrite\Auth\OAuth2\Supabase;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'supabase';
    }

    public static function getProviderClass(): string
    {
        return Supabase::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Supabase';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Supabase';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SUPABASE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '00000000000000000000000000000000';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '00000000000000000000000000000000';
    }
}
