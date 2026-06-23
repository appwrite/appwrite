<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Authentik extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'authentik',
    ];

    public function getProviderLabel(): string
    {
        return 'Authentik';
    }

    public function getClientIdExample(): string
    {
        return 'dTKOPa0000000000000000000000000000e7G8hv';
    }

    public function getClientSecretExample(): string
    {
        return 'ntQadq000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000Hp5WK';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'Authentik OAuth2 endpoint domain.',
            'default' => '',
            'example' => 'example.authentik.com',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Authentik';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_AUTHENTIK;
    }
}
