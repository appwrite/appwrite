<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Stripe;

use Appwrite\Auth\OAuth2\Stripe;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'stripe';
    }

    public static function getProviderClass(): string
    {
        return Stripe::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Stripe';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Stripe';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_STRIPE;
    }

    public static function getClientSecretParamName(): string
    {
        return 'apiSecretKey';
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return 'ca_UKibXX0000000000000000000006byvR';
    }

    public static function getClientSecretName(): string
    {
        return 'API Secret Key';
    }

    public static function getClientSecretExample(): string
    {
        return 'sk_51SfOd000000000000000000000000000000000000000000000000000000000000000000000000000000000000000QGWYfp';
    }
}
