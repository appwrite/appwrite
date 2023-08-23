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
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of function deployments.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('deploymentsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for function deployments storage.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of function builds.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for builds  storage.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for function build  compute.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('executionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for number of function executions.',
                'default' => [],
                'example' => [],
                'array' => true
            ])

            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for function execution compute.',
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
