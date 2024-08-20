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
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('bucketsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of buckets',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of files.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('filesStorageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of files storage (in bytes).',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('buckets', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of buckets per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('files', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of files per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of files storage (in bytes) per period .',
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
