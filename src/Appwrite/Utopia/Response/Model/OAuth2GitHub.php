<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2GitHub extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'github',
    ];

    public function getProviderLabel(): string
    {
        return 'GitHub';
    }

    public function getClientIdExample(): string
    {
        return 'e4d87900000000540733';
    }

    public function getClientSecretExample(): string
    {
        return '5e07c00000000000000000000000000000198bcc';
    }

    public function getClientIdDescription(): string
    {
        return parent::getClientIdDescription() . ' For GitHub Apps, use the "App ID" when both an App ID and client ID are available.';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2GitHub';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_GITHUB;
    }
}
