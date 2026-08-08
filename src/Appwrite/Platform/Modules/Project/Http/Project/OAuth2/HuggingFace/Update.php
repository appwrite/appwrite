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
        return '<Hugging Face OAuth app client ID>';
    }

    public static function getClientSecretName(): string
    {
        return 'Client Secret';
    }

    public static function getClientSecretExample(): string
    {
        return '<Hugging Face OAuth app client secret>';
    }
}