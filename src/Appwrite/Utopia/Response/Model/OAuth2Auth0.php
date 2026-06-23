<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Auth0 extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'auth0',
    ];

    public function getProviderLabel(): string
    {
        return 'Auth0';
    }

    public function getClientIdExample(): string
    {
        return 'OaOkIA000000000000000000005KLSYq';
    }

    public function getClientSecretExample(): string
    {
        return 'zXz0000-00000000000000000000000000000-00000000000000000000PJafnF';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'Auth0 OAuth2 endpoint domain.',
            'default' => '',
            'example' => 'example.us.auth0.com',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Auth0';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_AUTH0;
    }
}
