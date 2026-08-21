<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Google extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'google',
    ];

    public function getProviderLabel(): string
    {
        return 'Google';
    }

    public function getClientIdExample(): string
    {
        return '120000000095-92ifjb00000000000000000000g7ijfb.apps.googleusercontent.com';
    }

    public function getClientSecretExample(): string
    {
        return 'GOCSPX-2k8gsR0000000000000000VNahJj';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('prompt', [
            'type' => self::TYPE_ENUM,
            'description' => 'Google OAuth2 prompt values.',
            'default' => ['consent'],
            'example' => ['consent'],
            'array' => true,
            'enum' => ['none', 'consent', 'select_account'],
        ]);

        $this->addRule('nativeClientIds', [
            'type' => self::TYPE_STRING,
            'description' => 'Additional OAuth2 client IDs accepted as ID token audiences for native sign-in.',
            'default' => [],
            'example' => ['YOUR_ANDROID_CLIENT_ID.apps.googleusercontent.com'],
            'array' => true,
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Google';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_GOOGLE;
    }
}
