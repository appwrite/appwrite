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
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for the occupied storage size (in bytes).',
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
            ->addRule('bucketsCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of buckets.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('bucketsCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for buckets created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('bucketsRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for buckets read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('bucketsUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for buckets updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('bucketsDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for buckets deleted.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for files created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for files read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for files updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for files deleted.',
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
