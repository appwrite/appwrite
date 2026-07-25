<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Apple extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'apple',
    ];

    public function getProviderLabel(): string
    {
        return 'Apple';
    }

    public function getClientIdExample(): string
    {
        return 'ip.appwrite.app.web';
    }

    public function getClientSecretExample(): string
    {
        // Unused: this model overrides __construct() to expose keyId, teamId
        // and p8File instead of a single clientSecret field.
        return '';
    }

    public function getClientIdFieldName(): string
    {
        return 'serviceId';
    }

    public function getClientIdLabel(): string
    {
        return 'service ID';
    }

    public function __construct()
    {
        // Apple's OAuth2 app credential is split into three fields (.p8 file
        // contents, Key ID, Team ID) instead of a single clientSecret, so the
        // rules are defined manually rather than delegating to OAuth2Base.
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'OAuth2 provider ID.',
                'default' => '',
                'example' => 'apple',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'OAuth2 provider is active and can be used to create sessions.',
                'default' => false,
                'example' => false,
            ])
            ->addRule($this->getClientIdFieldName(), [
                'type' => self::TYPE_STRING,
                'description' => $this->getClientIdDescription(),
                'default' => '',
                'example' => $this->getClientIdExample(),
            ])
            ->addRule('keyId', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple OAuth2 key ID.',
                'default' => '',
                'example' => 'P4000000N8',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple OAuth2 team ID.',
                'default' => '',
                'example' => 'D4000000R6',
            ])
            ->addRule('p8File', [
                'type' => self::TYPE_STRING,
                'description' => 'Apple OAuth2 .p8 private key file contents. The secret key wrapped by the PEM markers is 200 characters long.',
                'default' => '',
                'example' => '-----BEGIN PRIVATE KEY-----MIGTAg...jy2Xbna-----END PRIVATE KEY-----',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Apple';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_APPLE;
    }
}
