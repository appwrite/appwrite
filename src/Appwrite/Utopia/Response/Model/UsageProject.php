<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageProject extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('executionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total function executions.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('documentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total number of documents.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databasesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total number of databases.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('usersTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total number of users.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total occupied storage size (in bytes).',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('bucketsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of total number of buckets.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('requests', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of number of requests per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('network', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated statistics of consumed bandwidth per period.',
                'default' => [],
                'example' => [],
                'array' => true
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
        return 'UsageProject';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_PROJECT;
    }
}
