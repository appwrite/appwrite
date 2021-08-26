<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;

class FunctionsUsage extends Model
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
            ->addRule('functions.executions', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function executions.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('functions.failures', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function execution failures.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('functions.compute', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for function execution duration.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'FunctionsUsage';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_FUNCTIONS_USAGE;
    }
}