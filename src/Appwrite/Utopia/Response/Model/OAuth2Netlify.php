<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Netlify extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'netlify',
    ];

    public function getProviderLabel(): string
    {
        return 'Netlify';
    }

    public function getClientIdExample(): string
    {
        return '00000000000000000000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return '00000000000000000000000000000000';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Netlify';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_NETLIFY;
    }
}
