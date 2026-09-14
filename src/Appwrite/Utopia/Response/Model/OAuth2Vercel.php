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

    public function __construct()
    {
        parent::__construct();

        $this->addRule('slug', [
            'type' => self::TYPE_STRING,
            'description' => 'Vercel integration slug. The URL slug of your integration from the Vercel Integration Console.',
            'default' => '',
            'example' => 'my-vercel-integration',
        ]);
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
