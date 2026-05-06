<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Alert extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Alert creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Alert update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('messageId', [
                'type' => self::TYPE_STRING,
                'description' => 'Stable message ID used for dedup.',
                'default' => '',
                'example' => 'session.create',
                'required' => false,
            ])
            ->addRule('type', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert type: info, warning, error.',
                'default' => 'info',
                'example' => 'info',
            ])
            ->addRule('channel', [
                'type' => self::TYPE_STRING,
                'description' => 'Channel: email, sms, push, console, webhook.',
                'default' => '',
                'example' => 'email',
            ])
            ->addRule('userId', [
                'type' => self::TYPE_STRING,
                'description' => 'User this alert is addressed to.',
                'default' => '',
                'example' => '5e5bb8c16897e',
                'required' => false,
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Team this alert is addressed to.',
                'default' => '',
                'example' => '5e5bb8c16897e',
                'required' => false,
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project the alert pertains to.',
                'default' => '',
                'example' => '5e5bb8c16897e',
                'required' => false,
            ])
            ->addRule('title', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert title.',
                'default' => '',
                'example' => 'New sign-in detected',
            ])
            ->addRule('body', [
                'type' => self::TYPE_STRING,
                'description' => 'Alert body.',
                'default' => '',
                'example' => 'A new device signed in to your account.',
            ])
            ->addRule('read', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the alert has been read.',
                'default' => false,
                'example' => false,
                'required' => false,
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
        return 'Alert';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_ALERT;
    }
}
