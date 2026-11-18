<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Roblox extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'roblox',
    ];

    public function getProviderLabel(): string
    {
        return 'Roblox';
    }

    public function getClientIdExample(): string
    {
        return '1234567890123456789';
    }

    public function getClientSecretExample(): string
    {
        return 'RBX-0000000000000000000000000000000000000000000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Roblox';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_ROBLOX;
    }
}
