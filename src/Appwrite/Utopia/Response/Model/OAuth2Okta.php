<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Okta extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'okta',
    ];

    public function getProviderLabel(): string
    {
        return 'Okta';
    }

    public function getClientIdExample(): string
    {
        return '0oa00000000000000698';
    }

    public function getClientSecretExample(): string
    {
        return 'Kiq0000000000000000000000000000000000000-00000000000H2L5-3SJ-vRV';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('domain', [
            'type' => self::TYPE_STRING,
            'description' => 'Okta OAuth2 domain.',
            'default' => '',
            'example' => 'trial-6400025.okta.com',
        ]);

        $this->addRule('authorizationServerId', [
            'type' => self::TYPE_STRING,
            'description' => 'Okta OAuth2 authorization server ID.',
            'default' => '',
            'example' => 'aus000000000000000h7z',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Okta';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_OKTA;
    }
}
