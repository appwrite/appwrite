<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Webhook extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Webhook ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Webhook name.',
                'example' => 'My Webhook',
            ])
            ->addRule('url', [
                'type' => 'string',
                'description' => 'Webhook URL endpoint.',
                'example' => 'https://example.com/webhook',
            ])
            ->addRule('events', [
                'type' => 'string',
                'description' => 'Webhook trigger events.',
                'default' => [],
                'example' => ['database.collections.update', 'database.collections.delete'],
                'array' => true,
            ])
            ->addRule('security', [
                'type' => 'boolean',
                'description' => 'Indicated if SSL / TLS Certificate verification is enabled.',
                'example' => true,
            ])
            ->addRule('httpUser', [
                'type' => 'string',
                'description' => 'HTTP basic authentication username.',
                'default' => '',
                'example' => 'username',
            ])
            ->addRule('httpPass', [
                'type' => 'string',
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