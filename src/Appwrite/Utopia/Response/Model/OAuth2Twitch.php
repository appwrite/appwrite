<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Twitch extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'twitch',
    ];

    public function getProviderLabel(): string
    {
        return 'Twitch';
    }

    public function getClientIdExample(): string
    {
        return 'vvi0in000000000000000000ikmt9p';
    }

    public function getClientSecretExample(): string
    {
        return 'pmapue000000000000000000zylw3v';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Twitch';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_TWITCH;
    }
}
