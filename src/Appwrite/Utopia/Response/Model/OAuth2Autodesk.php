<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Autodesk extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'autodesk',
    ];

    public function getProviderLabel(): string
    {
        return 'Autodesk';
    }

    public function getClientIdExample(): string
    {
        return '5zw90v00000000000000000000kVYXN7';
    }

    public function getClientSecretExample(): string
    {
        return '7I000000000000MW';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Autodesk';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_AUTODESK;
    }
}
