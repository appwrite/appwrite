<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageCollection extends Model
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
            ->addRule('documentsCount', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for total number of documents.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsCreate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents created.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsRead', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents read.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsUpdate', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents updated.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documentsDelete', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated stats for documents deleted.',
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
        return 'UsageCollection';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_COLLECTION;
    }
}
