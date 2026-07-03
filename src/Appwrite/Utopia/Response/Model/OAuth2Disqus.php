<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Disqus extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'disqus',
    ];

    public function getProviderLabel(): string
    {
        return 'Disqus';
    }

    public function getClientIdExample(): string
    {
        return 'cgegH70000000000000000000000000000000000000000000000000000Hr1nYX';
    }

    public function getClientSecretExample(): string
    {
        return 'W7Bykj00000000000000000000000000000000000000000000000000003o43w9';
    }

    public function getClientIdFieldName(): string
    {
        return 'publicKey';
    }

    public function getClientSecretFieldName(): string
    {
        return 'secretKey';
    }

    public function getClientIdLabel(): string
    {
        return 'public key';
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
        return 'OAuth2Disqus';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DISQUS;
    }
}
