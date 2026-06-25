<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Resource;

use Appwrite\Event\Resource\Parser;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParseSplitsIntoTypeIdSegmentsAndDropsTrailingCollectionName(): void
    {
        $this->assertSame(
            [
                ['type' => 'database', 'id' => 'db1'],
                ['type' => 'collection', 'id' => 'col1'],
            ],
            Parser::parse('database/db1/collection/col1/documents'),
        );

        $this->assertSame(
            [['type' => 'bucket', 'id' => 'b1']],
            Parser::parse('bucket/b1'),
        );

        $this->assertSame([], Parser::parse(''));
    }

    public function testAuditTargetReturnsLeafAndParentChain(): void
    {
        $segments = Parser::parse('database/db1/collection/col1/document/doc1');

        $this->assertSame(
            ['type' => 'document', 'id' => 'doc1', 'parent' => 'database/db1/collection/col1'],
            Parser::auditTarget($segments),
        );
    }

    public function testAuditTargetForTopLevelResourceHasEmptyParent(): void
    {
        $this->assertSame(
            ['type' => 'database', 'id' => 'db1', 'parent' => ''],
            Parser::auditTarget(Parser::parse('database/db1')),
        );

        $this->assertSame(
            ['type' => '', 'id' => '', 'parent' => ''],
            Parser::auditTarget([]),
        );
    }

    public function testCapForUsageStopsAtCollectionForDatabaseResources(): void
    {
        $segments = Parser::parse('database/db1/collection/col1/document/doc1');

        $this->assertSame(
            ['resource' => 'database/db1/table', 'resourceId' => 'col1'],
            Parser::capForUsage($segments),
        );
    }

    public function testCapForUsageCollapsesStorageToBucket(): void
    {
        $segments = Parser::parse('bucket/b1/file/f1');

        $this->assertSame(
            ['resource' => 'bucket', 'resourceId' => 'b1'],
            Parser::capForUsage($segments),
        );
    }

    public function testCapForUsageTreatsFunctionsAndSitesAsScalar(): void
    {
        $this->assertSame(
            ['resource' => 'function', 'resourceId' => 'fn1'],
            Parser::capForUsage(Parser::parse('function/fn1/execution/exec1')),
        );

        $this->assertSame(
            ['resource' => 'site', 'resourceId' => 's1'],
            Parser::capForUsage(Parser::parse('site/s1/deployment/d1')),
        );
    }

    public function testCapForUsageHandlesDatabaseWithoutCollection(): void
    {
        $this->assertSame(
            ['resource' => 'database', 'resourceId' => 'db1'],
            Parser::capForUsage(Parser::parse('database/db1')),
        );

        $this->assertSame(
            ['resource' => '', 'resourceId' => ''],
            Parser::capForUsage([]),
        );
    }
}
