<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Reddit extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'reddit',
    ];

    public function getProviderLabel(): string
    {
        return 'Reddit';
    }

    public function getClientIdExample(): string
    {
        return 'aBcD0000000000000000aB';
    }

    public function getClientSecretExample(): string
    {
        return 'xYz00000000000000000000000000a';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Reddit';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_REDDIT;
    }
}
