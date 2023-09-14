<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\DateTime;

class Message extends Any
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
            ->addRule('providerId', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider ID for the message.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('to', [
                'type' => self::TYPE_STRING,
                'description' => 'Message recipients.',
                'default' => '',
                'array' => true,
                'example' => ['user-1'],
            ])
            ->addRule('deliveryTime', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Time the message is delivered at.',
                'required' => false,
                'default' => DateTime::now(),
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('deliveryErrors', [
                'type' => self::TYPE_STRING,
                'description' => 'Delivery errors if any.',
                'required' => false,
                'default' => '',
                'array' => true,
                'example' => 'Credentials not valid.',
            ])
            ->addRule('deliveredTo', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of recipients the message was delivered to.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('delivered', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Status of delivery.',
                'default' => false,
                'example' => true,
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
