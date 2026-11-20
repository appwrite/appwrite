<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Bird extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'bird',
    ];

    public function getProviderLabel(): string
    {
        return 'Bird';
    }

    public function getClientIdExample(): string
    {
        return 'oac_01m7sv000000000000008r9qt6';
    }

    public function getClientSecretExample(): string
    {
        return 'D6XGgt0000000000000000000000000000000l7JvQ8';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Bird';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_BIRD;
    }
}
