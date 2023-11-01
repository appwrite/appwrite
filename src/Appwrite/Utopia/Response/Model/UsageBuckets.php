<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageBuckets extends Model
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
            ->addRule('filesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of bucket files.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of bucket files storage.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('files', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  statistics of bucket files per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated statistics of bucket storage files per period.',
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
        return 'UsageBuckets';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_BUCKETS;
    }
}
