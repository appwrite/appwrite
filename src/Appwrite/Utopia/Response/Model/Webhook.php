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
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Webhook';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_WEBHOOK;
    }
}