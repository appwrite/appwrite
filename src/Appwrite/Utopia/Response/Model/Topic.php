<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Topic extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Topic ID.',
                'default' => '',
                'example' => '259125845563242502',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Topic creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Topic update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'The name of the topic.',
                'default' => '',
                'example' => 'events',
            ])
            ->addRule('emailTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total count of email subscribers subscribed to the topic.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('smsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total count of SMS subscribers subscribed to the topic.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('pushTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total count of push subscribers subscribed to the topic.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('subscribe', [
                'type' => self::TYPE_STRING,
                'description' => 'Subscribe permissions.',
                'default' => ['users'],
                'example' => 'users',
                'array' => true,
            ])
            ->addRule('qos', [
                'type' => self::TYPE_INTEGER,
                'description' => 'MQTT QoS for delivery on this topic. Null lets the subscriber choose their level.',
                'default' => null,
                'example' => 1,
                'required' => false,
            ])
            ->addRule('expiry', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Message retention in seconds for offline delivery.',
                'default' => null,
                'example' => 604800,
                'required' => false,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Topic';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TOPIC;
    }
}
