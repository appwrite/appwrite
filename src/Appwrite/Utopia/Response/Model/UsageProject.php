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
            ->addRule('requestsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for number of requests.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('networkTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for consumed bandwidth.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('executionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for function executions.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('documentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for number of documents.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databasesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for number of databases.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('usersTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for number of users.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for the occupied storage size (in bytes).',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('bucketsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated stats for number of buckets.',
                'default' => 0,
                'example' => 0,
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
