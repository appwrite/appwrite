<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Etsy;

use Appwrite\Auth\OAuth2\Etsy;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'etsy';
    }

    public static function getProviderClass(): string
    {
        return Etsy::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Etsy';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Etsy';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ETSY;
    }

    public static function getClientIdParamName(): string
    {
        return 'keyString';
    }

    public static function getClientSecretParamName(): string
    {
        return 'sharedSecret';
    }

    public static function getClientIdName(): string
    {
        return 'Keystring';
    }

    public static function getClientIdExample(): string
    {
        return 'nsgzxh0000000000008j85a2';
    }

    public static function getClientSecretName(): string
    {
        return 'Shared Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'tp000000ru';
    }
}
