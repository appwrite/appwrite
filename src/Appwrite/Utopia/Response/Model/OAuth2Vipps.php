<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Vipps extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'vipps',
    ];

    public function getProviderLabel(): string
    {
        return 'Vipps MobilePay';
    }

    public function getClientIdExample(): string
    {
        return '9a3a4b3e-0000-0000-0000-0f1a6d1c3b2e';
    }

    public function getClientSecretExample(): string
    {
        return 'Ay1jb000000000000000000000000000000Vx3k';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Vipps';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_VIPPS;
    }
}
