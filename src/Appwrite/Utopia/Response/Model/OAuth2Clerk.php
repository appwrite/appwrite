<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Clerk extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'clerk',
    ];

    public function getProviderLabel(): string
    {
        return 'Clerk';
    }

    public function getClientIdExample(): string
    {
        return '0000000000000000';
    }

    public function getClientSecretExample(): string
    {
        return '00000000000000000000000000000000';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'Clerk Frontend API domain.',
            'default' => '',
            'example' => 'clerk.example.com',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Clerk';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_CLERK;
    }
}
