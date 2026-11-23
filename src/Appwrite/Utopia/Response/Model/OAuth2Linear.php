<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Linear extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'linear',
    ];

    public function getProviderLabel(): string
    {
        return 'Linear';
    }

    public function getClientIdExample(): string
    {
        return '5e1c0000000000000000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return '9f8e7d6c5b4a00000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Linear';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_LINEAR;
    }
}
