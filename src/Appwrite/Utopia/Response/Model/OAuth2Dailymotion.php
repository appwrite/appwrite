<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Dailymotion extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'dailymotion',
    ];

    public function getProviderLabel(): string
    {
        return 'Dailymotion';
    }

    public function getClientIdExample(): string
    {
        return '07a9000000000000067f';
    }

    public function getClientSecretExample(): string
    {
        return 'a399a90000000000000000000000000000d90639';
    }

    public function getClientIdFieldName(): string
    {
        return 'apiKey';
    }

    public function getClientSecretFieldName(): string
    {
        return 'apiSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'API key';
    }

    public function getClientSecretLabel(): string
    {
        return 'API secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Dailymotion';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DAILYMOTION;
    }
}
