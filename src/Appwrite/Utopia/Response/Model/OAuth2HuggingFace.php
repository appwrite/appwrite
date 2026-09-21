<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2HuggingFace extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'huggingface',
    ];

    public function getProviderLabel(): string
    {
        return 'Hugging Face';
    }

    public function getClientIdExample(): string
    {
        return '2ab9cff9-d711-40ad-a91e-b08a49c42d24';
    }

    public function getClientSecretExample(): string
    {
        return 'oauth_app_secret_wcLhRtl000000000000000000000xbNdLt';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2HuggingFace';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_HUGGINGFACE;
    }
}
