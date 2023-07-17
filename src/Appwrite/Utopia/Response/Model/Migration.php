<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Migration extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Variable creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration status.',
                'default' => '',
                'example' => 'pending',
            ])
            ->addRule('stage', [
                'type' => self::TYPE_STRING,
                'description' => 'Migration stage.',
                'default' => '',
                'example' => 'init',
            ])
            ->addRule('source', [
                'type' => self::TYPE_STRING,
                'description' => 'An object containing the source of the migration.',
                'default' => '',
                'example' => '{"type": "Appwrite", "endpoint": "xxxxxxxx", ...}',
            ])
            ->addRule('resources', [
                'type' => self::TYPE_STRING,
                'description' => 'Resources to migration.',
                'default' => [],
                'example' => ['user'],
                'array' => true
            ])
            ->addRule('statusCounters', [
                'type' => self::TYPE_JSON,
                'description' => 'A group of counters that represent the total progress of the migration.',
                'default' => [],
                'example' => '{"Database": {"PENDING": 0, "SUCCESS": 1, "ERROR": 0, "SKIP": 0, "PROCESSING": 0, "WARNING": 0}}',
            ])
            ->addRule('resourceData', [
                'type' => self::TYPE_JSON,
                'description' => 'An array of objects containing the report data of the resources that were migrated.',
                'default' => [],
                'example' => '[{"resource":"Database","id":"public","status":"SUCCESS","message":""}]',
            ])
            ->addRule('errors', [
                'type' => self::TYPE_STRING,
                'description' => 'All errors that occurred during the migration process.',
                'default' => [],
                'example' => [],
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
        return 'Migration';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MIGRATION;
    }
}
