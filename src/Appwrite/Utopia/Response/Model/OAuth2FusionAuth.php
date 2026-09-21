<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2FusionAuth extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'fusionauth',
    ];

    public function getProviderLabel(): string
    {
        return 'FusionAuth';
    }

    public function getClientIdExample(): string
    {
        return 'b2222c00-0000-0000-0000-000000862097';
    }

    public function getClientSecretExample(): string
    {
        return 'Jx4s0C0000000000000000000000000000000wGqLsc';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'FusionAuth OAuth2 endpoint domain.',
            'default' => '',
            'example' => 'example.fusionauth.io',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2FusionAuth';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_FUSIONAUTH;
    }
}
