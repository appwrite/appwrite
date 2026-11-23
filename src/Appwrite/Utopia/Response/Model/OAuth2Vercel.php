<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Vercel extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'vercel',
    ];

    public function getProviderLabel(): string
    {
        return 'Vercel';
    }

    public function getClientIdExample(): string
    {
        return 'cl_0000000000000000000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return 'vc_0000000000000000000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Vercel';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_VERCEL;
    }
}
