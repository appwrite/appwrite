<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Response\Filters;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filters\V27;
use PHPUnit\Framework\TestCase;

final class V27Test extends TestCase
{
    public function testRestoresLegacyMigrationResourceShape(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            '$id' => 'migration',
            'resourceId' => 'collection',
            'resourceInternalId' => '20',
            'resourceType' => 'collection',
            'parentResourceId' => 'database',
            'parentResourceInternalId' => '10',
            'parentResourceType' => 'database',
        ], Response::MODEL_MIGRATION);

        $this->assertSame([
            '$id' => 'migration',
            'resourceId' => 'database:collection',
            'resourceType' => 'database',
        ], $result);
    }

    public function testPreservesSingleRootMigration(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            '$id' => 'migration',
            'resourceId' => 'database',
            'resourceInternalId' => '10',
            'resourceType' => 'database',
            'parentResourceId' => '',
            'parentResourceInternalId' => '',
            'parentResourceType' => '',
        ], Response::MODEL_MIGRATION);

        $this->assertSame([
            '$id' => 'migration',
            'resourceId' => 'database',
            'resourceType' => 'database',
        ], $result);
    }

    public function testTransformsMigrationList(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'total' => 1,
            'migrations' => [[
                '$id' => 'migration',
                'resourceId' => 'collection',
                'resourceType' => 'collection',
                'parentResourceId' => 'database',
                'parentResourceType' => 'database',
            ]],
        ], Response::MODEL_MIGRATION_LIST);

        $this->assertSame([
            'total' => 1,
            'migrations' => [[
                '$id' => 'migration',
                'resourceId' => 'database:collection',
                'resourceType' => 'database',
            ]],
        ], $result);
    }
}
