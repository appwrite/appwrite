<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Line extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'line',
    ];

    public function getProviderLabel(): string
    {
        return 'LINE';
    }

    public function getClientIdExample(): string
    {
        return '1650000000';
    }

    public function getClientSecretExample(): string
    {
        return 'a1b2c3d4e5f600000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Line';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_LINE;
    }
}
