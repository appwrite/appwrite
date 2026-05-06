<?php

namespace Appwrite\Platform\Modules\Analytics\Storage;

use Utopia\Query\Schema\ClickHouse as ClickHouseSchema;
use Utopia\Query\Schema\Table;

class Schema
{
    public const TABLE_EVENTS = 'analytics_events';

    /**
     * Build the CREATE TABLE statement for analytics_events.
     *
     * Uses the schema builder for the column scaffolding and falls back to a
     * raw partition expression so we can keep monthly partitioning.
     */
    public static function eventsTableSql(): string
    {
        $schema = new ClickHouseSchema();

        $statement = $schema->create(self::TABLE_EVENTS, function (Table $table): void {
            $table->string('project_id', 36);
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

            $table->primary(['project_id', 'app_id', 'name', 'visitor_id', 'timestamp']);
            $table->partitionByRange('toYYYYMM(timestamp)');
        }, ifNotExists: true);

        return $statement->query;
    }
}
