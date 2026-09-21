<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2WordPress extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'wordpress',
    ];

    public function getProviderLabel(): string
    {
        return 'WordPress';
    }

    public function getClientIdExample(): string
    {
        return '130005';
    }

    public function getClientSecretExample(): string
    {
        return 'PlBfJS0000000000000000000000000000000000000000000000000000EdUZJk';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2WordPress';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_WORDPRESS;
    }
}
