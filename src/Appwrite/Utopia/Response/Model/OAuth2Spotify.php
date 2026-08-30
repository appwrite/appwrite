<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Spotify extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'spotify',
    ];

    public function getProviderLabel(): string
    {
        return 'Spotify';
    }

    public function getClientIdExample(): string
    {
        return '6ec271000000000000000000009beace';
    }

    public function getClientSecretExample(): string
    {
        return 'db068a000000000000000000008b5b9f';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Spotify';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_SPOTIFY;
    }
}
