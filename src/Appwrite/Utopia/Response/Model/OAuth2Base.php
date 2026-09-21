<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

abstract class OAuth2Base extends Model
{
    /**
     * Provider display label used in rule descriptions.
     *
     * @return string e.g. 'GitHub', 'Discord', 'Dropbox'
     */
    abstract public function getProviderLabel(): string;

    /**
     * Example value for the client ID rule.
     *
     * @return string
     */
    abstract public function getClientIdExample(): string;

    /**
     * Example value for the client secret rule.
     *
     * @return string
     */
    abstract public function getClientSecretExample(): string;

    /**
     * Public-facing field name of the client ID. Providers may override when
     * they use different terminology (e.g. Dropbox -> 'appKey').
     *
     * @return string
     */
    public function getClientIdFieldName(): string
    {
        return 'clientId';
    }

    /**
     * Public-facing field name of the client secret. Providers may override
     * when they use different terminology (e.g. Dropbox -> 'appSecret').
     *
     * @return string
     */
    public function getClientSecretFieldName(): string
    {
        return 'clientSecret';
    }

    /**
     * Human-readable label for the client ID, used in the generated rule
     * description. Providers may override (e.g. Dropbox -> 'app key').
     *
     * @return string
     */
    public function getClientIdLabel(): string
    {
        return 'client ID';
    }

    /**
     * Human-readable label for the client secret, used in the generated rule
     * description. Providers may override (e.g. Dropbox -> 'app secret').
     *
     * @return string
     */
    public function getClientSecretLabel(): string
    {
        return 'client secret';
    }

    /**
     * Rule description for the client ID. Auto-generated from the provider
     * label and client ID label. Providers may override to add extra context.
     *
     * @return string
     */
    public function getClientIdDescription(): string
    {
        return $this->getProviderLabel() . ' OAuth2 ' . $this->getClientIdLabel() . '.';
    }

    /**
     * Rule description for the client secret. Auto-generated from the provider
     * label and client secret label. Providers may override to add extra
     * context.
     *
     * @return string
     */
    public function getClientSecretDescription(): string
    {
        return $this->getProviderLabel() . ' OAuth2 ' . $this->getClientSecretLabel() . '.';
    }

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'OAuth2 provider ID.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'OAuth2 provider is active and can be used to create sessions.',
                'default' => false,
                'example' => false,
            ])
            ->addRule($this->getClientIdFieldName(), [
                'type' => self::TYPE_STRING,
                'description' => $this->getClientIdDescription(),
                'default' => '',
                'example' => $this->getClientIdExample(),
            ])
            ->addRule($this->getClientSecretFieldName(), [
                'type' => self::TYPE_STRING,
                'description' => $this->getClientSecretDescription(),
                'default' => '',
                'example' => $this->getClientSecretExample(),
            ]);
    }
}
