<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Bitbucket extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'bitbucket',
    ];

    public function getProviderLabel(): string
    {
        return 'Bitbucket';
    }

    public function getClientIdExample(): string
    {
        return 'Knt70000000000ByRc';
    }

    public function getClientSecretExample(): string
    {
        return 'NMfLZJ00000000000000000000TLQdDx';
    }

    public function getClientIdFieldName(): string
    {
        return 'key';
    }

    public function getClientSecretFieldName(): string
    {
        return 'secret';
    }

    public function getClientIdLabel(): string
    {
        return 'key';
    }

    public function getClientSecretLabel(): string
    {
        return 'secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Bitbucket';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_BITBUCKET;
    }
}
