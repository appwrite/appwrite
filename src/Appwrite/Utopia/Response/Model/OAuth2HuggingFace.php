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
        return 'hf_client_1234567890abcdef1234567890abcdef';
    }

    public function getClientSecretExample(): string
    {
        return 'hf_secret_1234567890abcdef1234567890abcdef';
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