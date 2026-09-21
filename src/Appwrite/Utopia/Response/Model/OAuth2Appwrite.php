<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Appwrite extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'appwrite',
    ];

    public function getProviderLabel(): string
    {
        return 'Appwrite';
    }

    public function getClientIdExample(): string
    {
        return '6a42000000000000b5a0';
    }

    public function getClientSecretExample(): string
    {
        return 'b86afd000000000000000000000000000000000000000000000000000ced5f93';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Appwrite';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_APPWRITE;
    }
}
