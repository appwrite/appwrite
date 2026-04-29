<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Google extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'google',
    ];

    public function getProviderLabel(): string
    {
        return 'Google';
    }

    public function getClientIdExample(): string
    {
        return '120000000095-92ifjb00000000000000000000g7ijfb.apps.googleusercontent.com';
    }

    public function getClientSecretExample(): string
    {
        return 'GOCSPX-2k8gsR0000000000000000VNahJj';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Google';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_GOOGLE;
    }
}
