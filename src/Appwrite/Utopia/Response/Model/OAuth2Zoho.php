<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Zoho extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'zoho',
    ];

    public function getProviderLabel(): string
    {
        return 'Zoho';
    }

    public function getClientIdExample(): string
    {
        return '1000.83C178000000000000000000RPNX0B';
    }

    public function getClientSecretExample(): string
    {
        return 'fb5cac000000000000000000000000000000a68f6e';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Zoho';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_ZOHO;
    }
}
