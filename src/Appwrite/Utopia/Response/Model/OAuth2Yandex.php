<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Yandex extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'yandex',
    ];

    public function getProviderLabel(): string
    {
        return 'Yandex';
    }

    public function getClientIdExample(): string
    {
        return '6a8a6a0000000000000000000091483c';
    }

    public function getClientSecretExample(): string
    {
        return 'bbf98500000000000000000000c75a63';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Yandex';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_YANDEX;
    }
}
