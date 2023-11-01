<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageFunction extends Model
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
            ->addRule('deploymentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics  of function deployments.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics  of function deployments storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of function builds.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregate total statistics for build storage.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('buildsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregate total function build compute time statistics.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total function executions statistics.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executionsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated for total function execution compute time statistics.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deployments', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for the number of function deployments per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for function deployment storage per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('builds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for the number of function builds per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for build storage per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for function build compute per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for the number of function executions per time period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics for function execution compute per time period.',
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
        return 'UsageFunction';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_FUNCTION;
    }
}
