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
                'description' => 'Time range of the usage stats.',
                'default' => '',
                'example' => '30d',
            ])
            ->addRule('databasesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number of databases.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('collectionsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number  of collections.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('tablesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total aggregated number  of tables.',
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
                'description' => 'Total aggregated number of total databases storage in bytes.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databasesReadsTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of databases reads.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databasesWritesTotal', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of databases writes.',
                'default' => 0,
                'example' => 0,
            ])
            ->addRule('databases', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of databases per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('collections', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of collections per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('tables', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of tables per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('documents', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of documents per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('rows', [
                'type' => Response::MODEL_METRIC,
                'description' => 'Aggregated number of rows per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('storage', [
                'type' => Response::MODEL_METRIC,
                'description' => 'An array of the aggregated number of databases storage in bytes per period.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesReads', [
                'type' => Response::MODEL_METRIC,
                'description' => 'An array of aggregated number of database reads.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('databasesWrites', [
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
