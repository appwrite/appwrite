<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Resend;

use Appwrite\Auth\OAuth2\Resend;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'resend';
    }

    public static function getProviderClass(): string
    {
        return Resend::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Resend';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Resend';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_RESEND;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '9c1e4b00000000000000000000000000000000000000000000000000a72d5f4';
    }
}
