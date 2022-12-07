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
            ->addRule('filesCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of files in this bucket.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('filesStorage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total storage of files in this bucket.',
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
