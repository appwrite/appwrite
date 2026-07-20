<?php

declare(strict_types=1);

namespace Tests\Unit\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filters\V27;
use PHPUnit\Framework\TestCase;

final class V27Test extends TestCase
{
    public function testSplitsLegacyMigrationResource(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'resourceId' => 'database:collection',
        ], 'migrations.createCSVImport');

        $this->assertSame([
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], $result);
    }

    public function testExplicitResourceIdsTakePrecedence(): void
    {
        $filter = new V27();

        $result = $filter->parse([
            'resourceId' => 'legacyDatabase:legacyCollection',
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], 'migrations.createJSONExport');

        $this->assertSame([
            'databaseId' => 'database',
            'collectionId' => 'collection',
        ], $result);
    }

    public function testLeavesMalformedResourceForValidation(): void
    {
        $filter = new V27();
        $content = ['resourceId' => 'database'];

        $this->assertSame($content, $filter->parse($content, 'migrations.createCSVExport'));
    }
}
