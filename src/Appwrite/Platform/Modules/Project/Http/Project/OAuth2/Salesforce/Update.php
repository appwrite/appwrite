<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Salesforce;

use Appwrite\Auth\OAuth2\Salesforce;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'salesforce';
    }

    public static function getProviderClass(): string
    {
        return Salesforce::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Salesforce';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Salesforce';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SALESFORCE;
    }

    public static function getClientIdParamName(): string
    {
        return 'customerKey';
    }

    public static function getClientSecretParamName(): string
    {
        return 'customerSecret';
    }

    public static function getClientIdName(): string
    {
        return 'Consumer Key';
    }

    public static function getClientIdExample(): string
    {
        return '3MVG9I0000000000000000000000000000000000000000000000000000000000000000000000000C5Aejq';
    }

    public static function getClientSecretName(): string
    {
        return 'Consumer Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '3w000000000000e2';
    }
}
