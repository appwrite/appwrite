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
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('collectionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of collections.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('tablesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of tables.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('documentsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of documents.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('rowsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of rows.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('storageTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of total storage used in bytes.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databaseReadsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of databases reads.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databaseWritesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of databases writes.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('collections', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of collections per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('tables', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of tables per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documents', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of documents per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('rows', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated  number of rows per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated storage used in bytes per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databaseReads', [
                'type' => Response::MODEL_METRIC,
                'description' => 'An array of aggregated number of database reads.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databaseWrites', [
                'type' => Response::MODEL_METRIC,
                'description' => 'An array of aggregated number of database writes.',
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
