<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Provider extends Model
{
    /**
     * @var bool
     */
    protected bool $public = false;

    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider name.',
                'default' => '',
                'example' => 'GitHub',
            ])
            ->addRule('appId', [
                'type' => self::TYPE_STRING,
                'description' => 'OAuth 2.0 application ID.',
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'OAuth 2.0 application secret. Might be JSON string if provider requires extra configuration.',
                'default' => '',
                'example' => 'Bpw_g9c2TGXxfgLshDbSaL8tsCcqgczQ',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Provider is active and can be used to create session.',
                'example' => '',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Provider';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_PROVIDER;
    }
}
