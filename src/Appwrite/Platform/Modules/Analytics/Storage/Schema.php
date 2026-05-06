<?php

namespace Appwrite\Platform\Modules\Analytics\Storage;

use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\Table;

class Schema
{
    public const DEFAULT_NAMESPACE = 'analytics';

    public const TABLE_EVENTS = 'events';

    /**
     * Build the qualified events table name for a given namespace.
     */
    public static function eventsTable(string $namespace = self::DEFAULT_NAMESPACE): string
    {
        $namespace = $namespace === '' ? self::DEFAULT_NAMESPACE : $namespace;

        return $namespace . '_' . self::TABLE_EVENTS;
    }

    /**
     * Build the CREATE TABLE statement for the events table.
     *
     * When sharedTables is true, a nullable `tenant` column is added and the
     * primary key / ORDER BY tuple is prefixed with `tenant` to keep per-tenant
     * scans efficient.
     */
    public static function eventsTableSql(string $namespace = self::DEFAULT_NAMESPACE, bool $sharedTables = false): string
    {
        $tableName = self::eventsTable($namespace);

        $schema = new ClickHouseSchema();

        $statement = $schema->create($tableName, function (Table $table) use ($sharedTables): void {
            if ($sharedTables) {
                $table->string('tenant', 36)->nullable();
            }

            $table->string('app_id', 36);
            $table->string('name', 64);
            $table->datetime('timestamp');
            $table->bigInteger('visitor_id')->unsigned();
            $table->string('hostname', 255);
            $table->string('pathname', 2048);
            $table->string('referrer', 2048);
            $table->string('country_code', 2);
            $table->string('screen_size', 16);
            $table->string('browser', 64);
            $table->string('operating_system', 64);

            $primary = $sharedTables
                ? ['tenant', 'app_id', 'name', 'visitor_id', 'timestamp']
                : ['app_id', 'name', 'visitor_id', 'timestamp'];

            $table->primary($primary);
            $table->partitionByRange('toYYYYMM(timestamp)');
        }, ifNotExists: true);

        return $statement->query;
    }
}
