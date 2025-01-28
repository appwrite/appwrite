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
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('filesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of bucket files.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of bucket files storage (in bytes).',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('files', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of bucket files per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of bucket storage files (in bytes) per period.',
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
