<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Tradeshift extends OAuth2Base
{
    public array $conditions = [
        '$id' => ['tradeshift', 'tradeshiftBox'],
    ];

    public function getProviderLabel(): string
    {
        return 'Tradeshift';
    }

    public function getClientIdExample(): string
    {
        return 'appwrite-test-org.appwrite-test-app';
    }

    public function getClientSecretExample(): string
    {
        return '7cb52700-0000-0000-0000-000000ca5b83';
    }

    public function getClientIdFieldName(): string
    {
        return 'oauth2ClientId';
    }

    public function getClientSecretFieldName(): string
    {
        return 'oauth2ClientSecret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Tradeshift';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_TRADESHIFT;
    }
}
