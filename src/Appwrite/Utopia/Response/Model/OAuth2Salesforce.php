<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Salesforce extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'salesforce',
    ];

    public function getProviderLabel(): string
    {
        return 'Salesforce';
    }

    public function getClientIdExample(): string
    {
        return '3MVG9I0000000000000000000000000000000000000000000000000000000000000000000000000C5Aejq';
    }

    public function getClientSecretExample(): string
    {
        return '3w000000000000e2';
    }

    public function getClientIdFieldName(): string
    {
        return 'customerKey';
    }

    public function getClientSecretFieldName(): string
    {
        return 'customerSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'consumer key';
    }

    public function getClientSecretLabel(): string
    {
        return 'consumer secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Salesforce';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_SALESFORCE;
    }
}
