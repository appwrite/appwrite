<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Cloudflare extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'cloudflare',
    ];

    /**
     * @return string
     */
    public function getProviderLabel(): string
    {
        return 'Cloudflare';
    }

    /**
     * @return string
     */
    public function getClientIdExample(): string
    {
        return '8c33c3da9e8f392k71m1f9dc1a190cb3707ad27ba4d19bff45c900e6dfet1f4a';
    }

    /**
     * @return string
     */
    public function getClientSecretExample(): string
    {
        return '2d106b111a390d9692ab9a8a295ac05668632b17bbb342d149209aaaaa100000';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('team', [
            'type' => self::TYPE_STRING,
            'description' => 'Cloudflare Zero Trust team name (the subdomain of cloudflareaccess.com).',
            'default' => '',
            'example' => 'acme',
        ]);
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Cloudflare';
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_CLOUDFLARE;
    }
}
