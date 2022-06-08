<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Webhook extends Model
{
    /**
     * @var bool
     */
    protected $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook name.',
                'default' => '',
                'example' => 'My Webhook',
            ])
            ->addRule('url', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook URL endpoint.',
                'default' => '',
                'example' => 'https://example.com/webhook',
            ])
            ->addRule('events', [
                'type' => self::TYPE_STRING,
                'description' => 'Webhook trigger events.',
                'default' => [],
                'example' => 'database.collections.update',
                'array' => true,
            ])
            ->addRule('security', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Indicated if SSL / TLS Certificate verification is enabled.',
                'default' => true,
                'example' => true,
            ])
            ->addRule('httpUser', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => self::TYPE_STRING,
                'description' => 'HTTP basic authentication password.',
                'default' => '',
                'example' => 'password',
            ])
            ->addRule('signatureKey', [
                'type' => self::TYPE_STRING,
                'description' => 'Signature key which can be used to validated incoming',
                'default' => '',
                'example' => 'ad3d581ca230e2b7059c545e5a0d1defd8c349f8979b12579e03890aab973bcd23318e4b0ff6190fa3be09d746358821e19147d995210d45855eb3c069f6fd6e',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Webhook';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_WEBHOOK;
    }
}
