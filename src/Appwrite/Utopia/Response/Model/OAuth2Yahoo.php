<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Yahoo extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'yahoo',
    ];

    public function getProviderLabel(): string
    {
        return 'Yahoo';
    }

    public function getClientIdExample(): string
    {
        return 'dj0yJm000000000000000000000000000000000000000000000000000000000000000000000000000000000000Z4PWRm';
    }

    public function getClientSecretExample(): string
    {
        return 'cf978f0000000000000000000000000000c5e2e9';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Yahoo';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_YAHOO;
    }
}
