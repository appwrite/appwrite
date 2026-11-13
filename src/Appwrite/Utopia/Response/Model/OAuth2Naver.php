<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Naver extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'naver',
    ];

    /**
     * @return string
     */
    public function getProviderLabel(): string
    {
        return 'Naver';
    }

    /**
     * @return string
     */
    public function getClientIdExample(): string
    {
        return 'jK8pQ0000000000000Zv';
    }

    /**
     * @return string
     */
    public function getClientSecretExample(): string
    {
        return '9f42A0000000000c1dEb';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Naver';
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_NAVER;
    }
}
