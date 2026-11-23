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
        return '8bb20000000000000000000000000000000000000000000000000000000040dd';
    }

    public function getClientSecretExample(): string
    {
        return '59bf00000000000000000000000000000000000000000000000000000000fe59';
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
