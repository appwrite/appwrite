<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2TikTok extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'tiktok',
    ];

    /**
     * @return string
     */
    public function getProviderLabel(): string
    {
        return 'TikTok';
    }

    /**
     * @return string
     */
    public function getClientIdLabel(): string
    {
        return 'client key';
    }

    /**
     * @return string
     */
    public function getClientIdExample(): string
    {
        return 'awz000000000tyw0';
    }

    /**
     * @return string
     */
    public function getClientSecretExample(): string
    {
        return '6wXewM00000000000000000000yXnite';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2TikTok';
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_TIKTOK;
    }
}
