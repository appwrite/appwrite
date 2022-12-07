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
            ->addRule('buckets', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of buckets.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of files.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for the occupied storage size (in bytes).',
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
        return 'StorageUsage';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_STORAGE;
    }
}
