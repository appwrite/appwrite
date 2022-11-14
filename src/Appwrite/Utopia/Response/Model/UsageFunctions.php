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
            ->addRule('executionsTotal', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for number of function executions.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('executionsFailure', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function execution failures.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('executionsSuccess', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function execution successes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('executionsTime', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function execution duration.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('buildsTotal', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for number of function builds.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('buildsFailure', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function build failures.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('buildsSuccess', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function build successes.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true
            ])
            ->addRule('buildsTime', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function build duration.',
                'default' => [],
                'example' => new \stdClass(),
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
