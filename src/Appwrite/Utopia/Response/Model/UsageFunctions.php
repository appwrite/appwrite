<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageFunctions extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_STRING,
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('functionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of functions.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of functions deployments.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of functions deployment storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of functions build.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'total aggregated sum of functions build storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of functions build compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('executionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total  aggregated number of functions execution.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('executionsTimeTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated sum of functions  execution compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('functions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of functions per period.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('deployments', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of functions deployment per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of  functions deployment storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('builds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of functions build per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of functions build storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated sum of  functions build compute time per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of  functions execution per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of functions execution compute time per period.',
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
        return 'UsageFunctions';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_FUNCTIONS;
    }
}
