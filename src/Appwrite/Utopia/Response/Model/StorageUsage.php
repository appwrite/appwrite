<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use stdClass;

class StorageUsage extends Model
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
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for the occupied storage size.',
                'default' => [],
                'example' => new stdClass,
                'array' => true 
            ])
            ->addRule('files', [
                'type' => Response::MODEL_METRIC_LIST,
                'description' => 'Aggregated stats for total number of files.',
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
        return 'StorageUsage';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_STORAGE_USAGE;
    }
}