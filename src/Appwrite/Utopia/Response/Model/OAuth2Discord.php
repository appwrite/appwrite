<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Discord extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'discord',
    ];

    public function getProviderLabel(): string
    {
        return 'Discord';
    }

    public function getClientIdExample(): string
    {
        return '950722000000343754';
    }

    public function getClientSecretExample(): string
    {
        return 'YmPXnM000000000000000000002zFg5D';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Discord';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DISCORD;
    }
}
