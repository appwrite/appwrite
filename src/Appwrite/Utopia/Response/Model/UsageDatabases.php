<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class UsageDatabases extends Model
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
            ->addRule('databasesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Aggregated total statistics of documents.',
                'default' => 0,
                'example' => 0,
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
            ->addRule('databases', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics of documents per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collections', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics  of collections per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documents', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated total statistics  of documents per period.',
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
        return 'UsageDatabases';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_USAGE_DATABASES;
    }
}
