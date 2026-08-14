<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Kick extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'kick',
    ];

    public function getProviderLabel(): string
    {
        return 'Kick';
    }

    public function getClientIdExample(): string
    {
        return '01KQ7C00000000000001MFHS32';
    }

    public function getClientSecretExample(): string
    {
        return '34ac5600000000000000000000000000000000000000000000000000e830c8b';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Kick';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_KICK;
    }
}
