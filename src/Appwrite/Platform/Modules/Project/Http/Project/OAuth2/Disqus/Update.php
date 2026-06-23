<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Disqus;

use Appwrite\Auth\OAuth2\Disqus;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'disqus';
    }

    public static function getProviderClass(): string
    {
        return Disqus::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Disqus';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Disqus';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_DISQUS;
    }

    public static function getClientIdParamName(): string
    {
        return 'publicKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'secretKey';
    }

    public static function getClientIdName(): string
    {
        return 'Public Key, also known as API Key';
    }

    public static function getClientIdExample(): string
    {
        return 'cgegH70000000000000000000000000000000000000000000000000000Hr1nYX';
    }

    public static function getClientSecretName(): string
    {
        return 'Secret Key, also known as API Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'W7Bykj00000000000000000000000000000000000000000000000000003o43w9';
    }
}
