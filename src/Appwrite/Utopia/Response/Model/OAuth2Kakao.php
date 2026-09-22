<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Kakao extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'kakao',
    ];

    /**
     * @return string
     */
    public function getProviderLabel(): string
    {
        return 'Kakao';
    }

    /**
     * @return string
     */
    public function getClientIdLabel(): string
    {
        return 'REST API key';
    }

    /**
     * @return string
     */
    public function getClientIdExample(): string
    {
        return '839ff5000000000000000000013206de';
    }

    /**
     * @return string
     */
    public function getClientSecretExample(): string
    {
        return 'jLNVOK00000000000000000000yJebea';
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Kakao';
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_KAKAO;
    }
}
