<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Bitly extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'bitly',
    ];

    public function getProviderLabel(): string
    {
        return 'Bitly';
    }

    public function getClientIdExample(): string
    {
        return 'd95151000000000000000000000000000067af9b';
    }

    public function getClientSecretExample(): string
    {
        return 'a13e250000000000000000000000000000d73095';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Bitly';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_BITLY;
    }
}
