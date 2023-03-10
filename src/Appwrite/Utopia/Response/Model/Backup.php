<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Backup extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Bucket creation time in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Bucket update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Backup permissions. [Learn more about permissions](/docs/permissions).',
                'default' => [],
                'example' => ['read("any")'],
                'array' => true,
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Bucket name.',
                'default' => '',
                'example' => 'Documents',
            ])
            ->addRule('description', [
                'type' => self::TYPE_STRING,
                'description' => 'Allowed file extensions.',
                'default' => [],
                'example' => 'This Backup was created on the 21st of March 2023',
                'array' => false
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Status of the backup. One of pending, processing, created, failed.',
                'default' => [],
                'example' => 'pending',
                'array' => false
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
        return 'Backup';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_BACKUP;
    }
}
