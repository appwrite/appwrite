<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Notion extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'notion',
    ];

    public function getProviderLabel(): string
    {
        return 'Notion';
    }

    public function getClientIdExample(): string
    {
        return '341d8700-0000-0000-0000-000000446ee3';
    }

    public function getClientSecretExample(): string
    {
        return 'secret_dLUr4b000000000000000000000000000000lFHAa9';
    }

    public function getClientIdFieldName(): string
    {
        return 'oauthClientId';
    }

    public function getClientSecretFieldName(): string
    {
        return 'oauthClientSecret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Notion';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_NOTION;
    }
}
