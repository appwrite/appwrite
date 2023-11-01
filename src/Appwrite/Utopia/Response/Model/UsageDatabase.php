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
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of collections.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('documentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of documents.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('collections', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  statistics collections per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documents', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  statistics of documents per period.',
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
