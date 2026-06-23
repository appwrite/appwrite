<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Dropbox extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'dropbox',
    ];

    public function getProviderLabel(): string
    {
        return 'Dropbox';
    }

    public function getClientIdExample(): string
    {
        return 'jl000000000009t';
    }

    public function getClientSecretExample(): string
    {
        return 'g200000000000vw';
    }

    public function getClientIdFieldName(): string
    {
        return 'appKey';
    }

    public function getClientSecretFieldName(): string
    {
        return 'appSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'app key';
    }

    public function getClientSecretLabel(): string
    {
        return 'app secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Dropbox';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_DROPBOX;
    }
}
