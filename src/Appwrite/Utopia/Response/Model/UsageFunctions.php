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
                'description' => 'The time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('functionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of functions.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of function deployments.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of function deployments storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of function builds.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of builds storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of build compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('executionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of functions executions.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of functions execution compute time.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('functions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of functions per period.',
                'default' => 0,
                'example' => 0,
                'array' => true
            ])
            ->addRule('deployments', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function deployments per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function deployments storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('builds', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function builds per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of builds storage per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function build compute time per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executions', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function executions per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of function execution compute time per period.',
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
