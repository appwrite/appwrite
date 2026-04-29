<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Figma extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'figma',
    ];

    public function getProviderLabel(): string
    {
        return 'Figma';
    }

    public function getClientIdExample(): string
    {
        return 'byay5H0000000000VtiI40';
    }

    public function getClientSecretExample(): string
    {
        return 'yEpOYn0000000000000000004iIsU5';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Figma';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_FIGMA;
    }
}
