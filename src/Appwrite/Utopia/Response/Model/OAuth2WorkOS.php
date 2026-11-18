<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2WorkOS extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'workos',
    ];

    public function getProviderLabel(): string
    {
        return 'WorkOS';
    }

    public function getClientIdExample(): string
    {
        return 'client_01H0000000000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return 'wos0000000000000000000000000000000000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2WorkOS';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_WORKOS;
    }
}
