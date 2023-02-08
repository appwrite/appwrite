<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageDatabase extends Model
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
            ->addRule('collectionsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of collections.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsTotal', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of documents.',
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
        return 'UsageDatabase';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_DATABASE;
    }
}
