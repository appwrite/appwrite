<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2X extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'x',
    ];

    public function getProviderLabel(): string
    {
        return 'X';
    }

    public function getClientIdExample(): string
    {
        return 'slzZV0000000000000NFLaWT';
    }

    public function getClientSecretExample(): string
    {
        return 'tkEPkp00000000000000000000000000000000000000FTxbI9';
    }

    public function getClientIdFieldName(): string
    {
        return 'customerKey';
    }

    public function getClientSecretFieldName(): string
    {
        return 'secretKey';
    }

    public function getClientIdLabel(): string
    {
        return 'customer key';
    }

    public function getClientSecretLabel(): string
    {
        return 'secret key';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2X';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_X;
    }
}
