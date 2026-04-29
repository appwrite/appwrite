<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Zoom extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'zoom',
    ];

    public function getProviderLabel(): string
    {
        return 'Zoom';
    }

    public function getClientIdExample(): string
    {
        return 'QMAC00000000000000w0AQ';
    }

    public function getClientSecretExample(): string
    {
        return 'GAWsG4000000000000000000007U01ON';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Zoom';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_ZOOM;
    }
}
