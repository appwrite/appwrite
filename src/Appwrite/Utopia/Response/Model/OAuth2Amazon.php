<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Amazon extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'amazon',
    ];

    public function getProviderLabel(): string
    {
        return 'Amazon';
    }

    public function getClientIdExample(): string
    {
        return 'amzn1.application-oa2-client.87400c00000000000000000000063d5b2';
    }

    public function getClientSecretExample(): string
    {
        return '79ffe4000000000000000000000000000000000000000000000000000002de55';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Amazon';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_AMAZON;
    }
}
