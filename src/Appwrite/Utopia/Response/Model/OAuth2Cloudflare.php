<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Cloudflare extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'cloudflare',
    ];

    /**
     * @return string
     */
    public function getProviderLabel(): string
    {
        return 'Cloudflare';
    }

    /**
     * @return string
     */
    public function getClientIdExample(): string
    {
        return '4b866000000000000000000000c9e4e2';
    }

    /**
     * @return string
     */
    public function getClientSecretExample(): string
    {
        return 'cfoc_5Q6YRl0000000000000000000000000000000000003d214f';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Cloudflare';
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_CLOUDFLARE;
    }
}
