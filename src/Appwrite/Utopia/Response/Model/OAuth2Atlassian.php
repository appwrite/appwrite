<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Atlassian extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'atlassian',
    ];

    public function getProviderLabel(): string
    {
        return 'Atlassian';
    }

    public function getClientIdExample(): string
    {
        return 'uQ7mZ3s0000000000000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return 'ATOA0000000000000000000000000000000000000000000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Atlassian';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_ATLASSIAN;
    }
}
