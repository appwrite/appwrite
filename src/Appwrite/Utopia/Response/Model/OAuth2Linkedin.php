<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Linkedin extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'linkedin',
    ];

    public function getProviderLabel(): string
    {
        return 'LinkedIn';
    }

    public function getClientIdExample(): string
    {
        return '770000000000dv';
    }

    public function getClientSecretExample(): string
    {
        return 'WPL_AP1.2Bf0000000000000./HtlYw==';
    }

    public function getClientSecretFieldName(): string
    {
        return 'primaryClientSecret';
    }

    public function getClientSecretLabel(): string
    {
        return 'primary client secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Linkedin';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_LINKEDIN;
    }
}
