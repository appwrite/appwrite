<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;

class UsageCollection extends Model
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
            ->addRule('documents.count', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('documents.create', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents created.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('documents.read', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents read.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('documents.update', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents updated.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('documents.delete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for documents deleted.',
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
        return 'UsageCollection';
    }

    /**
     * Get Type
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_USAGE_COLLECTION;
    }
}