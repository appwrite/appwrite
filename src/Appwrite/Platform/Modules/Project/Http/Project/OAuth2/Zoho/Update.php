<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoho;

use Appwrite\Auth\OAuth2\Zoho;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'zoho';
    }

    public static function getProviderClass(): string
    {
        return Zoho::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Zoho';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Zoho';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_ZOHO;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '1000.83C178000000000000000000RPNX0B';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'fb5cac000000000000000000000000000000a68f6e';
    }
}
