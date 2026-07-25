<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Microsoft extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'microsoft',
    ];

    public function getProviderLabel(): string
    {
        return 'Microsoft';
    }

    public function getClientIdExample(): string
    {
        return '00001111-aaaa-2222-bbbb-3333cccc4444';
    }

    public function getClientSecretExample(): string
    {
        return 'A1bC2dE3fH4iJ5kL6mN7oP8qR9sT0u';
    }

    public function getClientIdFieldName(): string
    {
        return 'applicationId';
    }

    public function getClientSecretFieldName(): string
    {
        return 'applicationSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'application ID';
    }

    public function getClientSecretLabel(): string
    {
        return 'application secret';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('tenant', [
            'type' => self::TYPE_STRING,
            'description' => 'Microsoft Entra ID tenant identifier. Use \'common\', \'organizations\', \'consumers\' or a specific tenant ID.',
            'default' => '',
            'example' => 'common',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Microsoft';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_MICROSOFT;
    }
}
