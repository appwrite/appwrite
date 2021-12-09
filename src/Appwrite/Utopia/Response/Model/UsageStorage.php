<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageStorage extends Model
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
            ->addRule('filesStorage', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for the occupied storage size by files (in bytes).',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('tagsStorage', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for the occupied storage size by tags (in bytes).',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('filesCount', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of files.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('bucketsCount', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of buckets.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('bucketsCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for buckets created.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('bucketsRead', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for buckets read.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('bucketsUpdate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for buckets updated.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('bucketsDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for buckets deleted.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('filesCreate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for files created.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('filesRead', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for files read.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('filesUpdate', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for files updated.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true 
            ])
            ->addRule('filesDelete', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for files deleted.',
                'default' => [],
                'example' => new \stdClass,
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
        return 'StorageUsage';
    }

    /**
     * Get Type
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_USAGE_STORAGE;
    }
}