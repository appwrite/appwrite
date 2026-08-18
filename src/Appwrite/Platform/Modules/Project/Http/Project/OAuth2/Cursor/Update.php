<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Cursor;

use Appwrite\Auth\OAuth2\Cursor;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'cursor';
    }

    public static function getProviderClass(): string
    {
        return Cursor::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Cursor';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Cursor';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_CURSOR;
    }

    public static function getClientIdName(): string
    {
        return 'App ID (also known as Client ID)';
    }

    public static function getClientIdExample(): string
    {
        return 'app_01k5wz9v3rq8tembsy0d4hxnpc';
    }

    public static function getClientSecretParamName(): string
    {
        return 'privateKey';
    }

    public static function getClientSecretName(): string
    {
        return 'Ed25519 private key (PKCS#8 PEM)';
    }

    public static function getClientSecretExample(): string
    {
        return '-----BEGIN PRIVATE KEY-----\nMC4CAQAwBQYDK2VwBCIEIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\n-----END PRIVATE KEY-----';
    }

    public static function getClientSecretHint(): string
    {
        return 'Only the matching public key is registered on the Cursor app.';
    }
}
