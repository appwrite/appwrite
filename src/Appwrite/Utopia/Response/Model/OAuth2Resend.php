<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Resend extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'resend',
    ];

    public function getProviderLabel(): string
    {
        return 'Resend';
    }

    public function getClientIdExample(): string
    {
        return 'f47ac10b-58cc-4372-a567-0e02b2c3d479';
    }

    public function getClientSecretExample(): string
    {
        return '9c1e4b00000000000000000000000000000000000000000000000000a72d5f4';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Resend';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_RESEND;
    }
}
