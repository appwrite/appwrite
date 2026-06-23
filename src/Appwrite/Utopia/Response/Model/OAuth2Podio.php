<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Podio extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'podio',
    ];

    public function getProviderLabel(): string
    {
        return 'Podio';
    }

    public function getClientIdExample(): string
    {
        return 'appwrite-oauth-test-app';
    }

    public function getClientSecretExample(): string
    {
        return 'Rn247T0000000000000000000000000000000000000000000000000000W2zWTN';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Podio';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_PODIO;
    }
}
