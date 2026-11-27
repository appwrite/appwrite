<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\Document;

class Notification extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Notification ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Notification creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Notification update date in ISO 8601 format.',
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
                'description' => 'Notification type: info, warning, error. A monitor alert\'s notification takes its severity, which can also be critical.',
                'default' => 'info',
                'example' => 'info',
            ])
            ->addRule('channel', [
                'type' => self::TYPE_STRING,
                'description' => 'Channel: email, sms, push, console, webhook.',
                'default' => '',
                'example' => 'email',
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource type this notification is addressed to.',
                'default' => '',
                'example' => 'users',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Resource ID this notification is addressed to.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('parentResourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'Parent resource type for the notification.',
                'default' => '',
                'example' => 'projects',
            ])
            ->addRule('parentResourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'Parent resource ID for the notification.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project the notification pertains to.',
                'default' => '',
                'example' => '5e5bb8c16897e',
                'required' => false,
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Organization the notification pertains to. Empty when the notification is not about an organization.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('subjectType', [
                'type' => self::TYPE_STRING,
                'description' => 'Kind of thing the notification is about, such as alerts. Empty when it is about nothing that changes.',
                'default' => '',
                'example' => 'alerts',
            ])
            ->addRule('subjectId', [
                'type' => self::TYPE_STRING,
                'description' => 'ID of the thing the notification is about. Each later change to it updates this notification. Empty when subjectType is empty.',
                'default' => '',
                'example' => '5e5bb8c16897e',
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Where the subject stands: firing, acknowledged or resolved for an alert. Empty when subjectType is empty.',
                'default' => '',
                'example' => 'firing',
            ])
            ->addRule('title', [
                'type' => self::TYPE_STRING,
                'description' => 'Notification title.',
                'default' => '',
                'example' => 'New sign-in detected',
            ])
            ->addRule('body', [
                'type' => self::TYPE_STRING,
                'description' => 'Notification body.',
                'default' => '',
                'example' => 'A new device signed in to your account.',
            ])
            ->addRule('read', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the notification has been read.',
                'default' => false,
                'example' => false,
                'required' => false,
            ])
            ->addRule('firstSeen', [
                'type' => self::TYPE_DATETIME,
                'description' => 'First time the notification was viewed from a notification logo.',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'required' => false,
            ])
            ->addRule('lastSeen', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Most recent time the notification was viewed from a notification logo.',
                'default' => null,
                'example' => self::TYPE_DATETIME_EXAMPLE,
                'required' => false,
            ])
        ;
    }

    /**
     * Notifications stored before a notification could name its organization or subject have none.
     */
    public function filter(Document $document): Document
    {
        foreach (['teamId', 'subjectType', 'subjectId', 'status'] as $key) {
            $document->setAttribute($key, (string) $document->getAttribute($key, ''));
        }

        return $document;
    }

    public function getName(): string
    {
        return 'Notification';
    }

    public function getType(): string
    {
        return Response::MODEL_NOTIFICATION;
    }
}
