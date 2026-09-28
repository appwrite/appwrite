<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Webflow extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'webflow',
    ];

    public function getProviderLabel(): string
    {
        return 'Webflow';
    }

    public function getClientIdExample(): string
    {
        return '0d6a00000000000000000000000000000000000000000000000000000000f31c';
    }

    public function getClientSecretExample(): string
    {
        return '7b1e00000000000000000000000000000000000000000000000000000000ac95';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Webflow';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_WEBFLOW;
    }
}
