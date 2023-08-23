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
                'type' => self::TYPE_STRING,
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('requestsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of requests.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('network', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for consumed bandwidth.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for function executions.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of documents.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of databases.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('usersTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of users.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for the occupied storage size (in bytes).',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('bucketsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of buckets.',
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
