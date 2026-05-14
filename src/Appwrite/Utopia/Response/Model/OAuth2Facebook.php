<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Facebook extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'facebook',
    ];

    public function getProviderLabel(): string
    {
        return 'Facebook';
    }

    public function getClientIdExample(): string
    {
        return '260600000007694';
    }

    public function getClientSecretExample(): string
    {
        return '2d0b2800000000000000000000d38af4';
    }

    public function getClientIdFieldName(): string
    {
        return 'appId';
    }

    public function getClientSecretFieldName(): string
    {
        return 'appSecret';
    }

    public function getClientIdLabel(): string
    {
        return 'app ID';
    }

    public function getClientSecretLabel(): string
    {
        return 'app secret';
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Facebook';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_FACEBOOK;
    }
}
