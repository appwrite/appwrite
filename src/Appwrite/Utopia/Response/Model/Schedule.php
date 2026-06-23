<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Schedule extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Schedule ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Schedule creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Schedule update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('resourceType', [
                'type' => self::TYPE_STRING,
                'description' => 'The resource type associated with this schedule.',
                'default' => '',
                'example' => 'function',
            ])
            ->addRule('resourceId', [
                'type' => self::TYPE_STRING,
                'description' => 'The resource ID associated with this schedule.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('resourceUpdatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Change-tracking timestamp used by the scheduler to detect resource changes in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'The project ID associated with this schedule.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('schedule', [
                'type' => self::TYPE_STRING,
                'description' => 'The CRON schedule expression.',
                'default' => '',
                'example' => '5 4 * * *',
            ])
            ->addRule('data', [
                'type' => self::TYPE_JSON,
                'description' => 'Schedule data used to store resource-specific context needed for execution.',
                'default' => [],
                'example' => [],
            ])
            ->addRule('active', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the schedule is active.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('region', [
                'type' => self::TYPE_STRING,
                'description' => 'The region where the schedule is deployed.',
                'default' => '',
                'example' => 'fra',
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
        return 'Schedule';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SCHEDULE;
    }
}
