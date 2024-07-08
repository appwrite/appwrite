<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\DateTime;

class Message extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Message ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Message creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Message update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('providerType', [
                'type' => self::TYPE_STRING,
                'description' => 'Message provider type.',
                'default' => '',
                'example' => MESSAGE_TYPE_EMAIL,
            ])
            ->addRule('topics', [
                'type' => self::TYPE_STRING,
                'description' => 'Topic IDs set as recipients.',
                'default' => '',
                'array' => true,
                'example' => ['5e5ea5c16897e'],
            ])
            ->addRule('users', [
                'type' => self::TYPE_STRING,
                'description' => 'User IDs set as recipients.',
                'default' => '',
                'array' => true,
                'example' => ['5e5ea5c16897e'],
            ])
            ->addRule('targets', [
                'type' => self::TYPE_STRING,
                'description' => 'Target IDs set as recipients.',
                'default' => '',
                'array' => true,
                'example' => ['5e5ea5c16897e'],
            ])
            ->addRule('scheduledAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The scheduled time for message.',
                'required' => false,
                'default' => DateTime::now(),
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('deliveredAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'The time when the message was delivered.',
                'required' => false,
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('deliveryErrors', [
                'type' => self::TYPE_STRING,
                'description' => 'Delivery errors if any.',
                'required' => false,
                'default' => '',
                'array' => true,
                'example' => ['Failed to send message to target 5e5ea5c16897e: Credentials not valid.'],
            ])
            ->addRule('deliveredTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of recipients the message was delivered to.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('data', [
                'type' => self::TYPE_JSON,
                'description' => 'Data of the message.',
                'default' => [],
                'example' => [
                    'subject' => 'Welcome to Appwrite',
                    'content' => 'Hi there, welcome to Appwrite family.',
                ],
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Status of delivery.',
                'default' => 'draft',
                'example' => 'Message status can be one of the following: draft, processing, scheduled, sent, or failed.',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Message';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MESSAGE;
    }
}
