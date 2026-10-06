<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\HuggingFace;

use Appwrite\Auth\OAuth2\HuggingFace;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Utopia\Response;

class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'huggingface';
    }

    public static function getProviderClass(): string
    {
        return HuggingFace::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Hugging Face';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2HuggingFace';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_HUGGINGFACE;
    }

    public static function getClientIdName(): string
    {
        return 'Client ID';
    }

    public static function getClientIdExample(): string
    {
        return '2ab9cff9-d711-40ad-a91e-b08a49c42d24';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return 'oauth_app_secret_wcLhRtl000000000000000000000xbNdLt';
    }
}
