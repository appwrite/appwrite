<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Oidc extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'oidc',
    ];

    public function getProviderLabel(): string
    {
        return 'OpenID Connect';
    }

    public function getClientIdExample(): string
    {
        return 'qibI2x0000000000000000000000000006L2YFoG';
    }

    public function getClientSecretExample(): string
    {
        return 'Ah68ed000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000003qpcHV';
    }

    public function __construct()
    {
        parent::__construct();

        $this
            ->addRule('wellKnownURL', [
                'type' => self::TYPE_STRING,
                'description' => 'OpenID Connect well-known configuration URL. When set, authorization, token, and user info endpoints can be discovered automatically.',
                'default' => '',
                'example' => 'https://myoauth.com/.well-known/openid-configuration',
            ])
            ->addRule('authorizationURL', [
                'type' => self::TYPE_STRING,
                'description' => 'OpenID Connect authorization endpoint URL.',
                'default' => '',
                'example' => 'https://myoauth.com/oauth2/authorize',
            ])
            ->addRule('tokenUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'OpenID Connect token endpoint URL.',
                'default' => '',
                'example' => 'https://myoauth.com/oauth2/token',
            ])
            ->addRule('userInfoUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'OpenID Connect user info endpoint URL.',
                'default' => '',
                'example' => 'https://myoauth.com/oauth2/userinfo',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Oidc';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_OIDC;
    }
}
