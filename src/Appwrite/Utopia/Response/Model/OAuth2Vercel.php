<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Vercel extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'vercel',
    ];

    public function getProviderLabel(): string
    {
        return 'Vercel';
    }

    public function getClientIdLabel(): string
    {
        return 'client id';
    }

    public function getClientIdExample(): string
    {
        return 'oac_xxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    }

    public function getClientSecretExample(): string
    {
        return 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    }

    public function getName(): string
    {
        return 'OAuth2Vercel';
    }

    public function getType(): string
    {
        return Response::MODEL_OAUTH2_VERCEL;
    }
}
