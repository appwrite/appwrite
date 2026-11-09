<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Transfer extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Transfer ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of the resource being transferred.',
                'default' => '',
                'example' => 'projects',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the resource being transferred.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resourceName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the resource being transferred, so the recipient can see what they are claiming.',
                'default' => '',
                'example' => 'My Project',
            ])
            ->addRule('senderType', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of the sender that owns the resource.',
                'default' => '',
                'example' => 'teams',
            ])
            ->addRule('senderId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the sender that owns the resource.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('senderName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the sender that owns the resource.',
                'default' => '',
                'example' => 'My Organization',
            ])
            ->addRule('recipientType', [
                'type' => self::TYPE_STRING,
                'description' => 'Type of the recipient that can claim the resource.',
                'default' => '',
                'example' => 'teams',
            ])
            ->addRule('recipientId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the only recipient that can claim the resource. Empty when any recipient of the recipient type can claim it.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('recipientName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the only recipient that can claim the resource. Empty when any recipient of the recipient type can claim it.',
                'default' => '',
                'example' => 'Their Organization',
            ])
            ->addRule('expire', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Transfer expiration date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('secret', [
                'type' => self::TYPE_STRING,
                'description' => 'Secret the recipient uses to claim the resource. Only returned to the sender.',
                'default' => '',
                'example' => '919c2d18fb5d4...a2ae413da83346ad2',
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
        return 'Transfer';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TRANSFER;
    }
}
