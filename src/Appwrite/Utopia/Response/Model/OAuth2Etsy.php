<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Etsy extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'etsy',
    ];

    public function getProviderLabel(): string
    {
        return 'Etsy';
    }

    public function getClientIdExample(): string
    {
        return 'nsgzxh0000000000008j85a2';
    }

    public function getClientSecretExample(): string
    {
        return 'tp000000ru';
    }

    public function getClientIdFieldName(): string
    {
        return 'keyString';
    }

    public function getClientSecretFieldName(): string
    {
        return 'sharedSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'keystring';
    }

    public function getClientSecretLabel(): string
    {
        return 'shared secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Etsy';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_ETSY;
    }
}
