<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Keycloak extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'keycloak',
    ];

    public function getProviderLabel(): string
    {
        return 'Keycloak';
    }

    public function getClientIdExample(): string
    {
        return 'appwrite-o0000000st-app';
    }

    public function getClientSecretExample(): string
    {
        return 'jdjrJd00000000000000000000HUsaZO';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'Keycloak OAuth2 endpoint domain.',
            'default' => '',
            'example' => 'keycloak.example.com',
        ]);

        $this->addRule('realmName', [
            'type' => self::TYPE_STRING,
            'description' => 'Keycloak OAuth2 realm name.',
            'default' => '',
            'example' => 'appwrite-realm',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Keycloak';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_KEYCLOAK;
    }
}
