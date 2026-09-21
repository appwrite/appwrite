<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class OAuth2Gitlab extends OAuth2Base
{
    public array $conditions = [
        '$id' => 'gitlab',
    ];

    public function getProviderLabel(): string
    {
        return 'GitLab';
    }

    public function getClientIdExample(): string
    {
        return 'd41ffe0000000000000000000000000000000000000000000000000000d5e252';
    }

    public function getClientSecretExample(): string
    {
        return 'gloas-838cfa0000000000000000000000000000000000000000000000000000ecbb38';
    }

    public function getClientIdFieldName(): string
    {
        return 'applicationId';
    }

    public function getClientSecretFieldName(): string
    {
        return 'secret';
    }

    public function getClientIdLabel(): string
    {
        return 'application ID';
    }

    public function getClientSecretLabel(): string
    {
        return 'secret';
    }

    public function __construct()
    {
        parent::__construct();

        $this->addRule('endpoint', [
            'type' => self::TYPE_STRING,
            'description' => 'GitLab OAuth2 endpoint URL. Defaults to https://gitlab.com for self-hosted instances.',
            'default' => '',
            'example' => 'https://gitlab.com',
        ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Gitlab';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_GITLAB;
    }
}
