<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Slack extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'slack',
    ];

    public function getProviderLabel(): string
    {
        return 'Slack';
    }

    public function getClientIdExample(): string
    {
        return '23000000089.15000000000023';
    }

    public function getClientSecretExample(): string
    {
        return '81656000000000000000000000f3d2fd';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Slack';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_SLACK;
    }
}
