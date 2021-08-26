<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;

class DatabaseUsage extends Model
{
    public function __construct()
    {
        $this
            ->addRule('range', [
                'type' => self::TYPE_INTEGER,
                'description' => 'The value of this metric at a timestamp.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('documents.count', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('collections.count', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of collections.',
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
            ->addRule('collections.create', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections created.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('collections.read', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections read.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('collections.update', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections updated.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('collections.delete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for collections delete.',
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
        return 'DatabaseUsage';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_DATABASE_USAGE;
    }
}