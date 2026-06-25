<?php

declare(strict_types=1);

namespace Tests\Unit\Event\Resource;

use Appwrite\Event\Resource\Parser;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class ParserTest extends TestCase
{
    public function testRenderSubstitutesRequestAndResponseNamespaces(): void
    {
        $rendered = Parser::render('database/{request.databaseId}/collection/{response.$id}', [
            'request' => ['databaseId' => 'db1'],
            'response' => ['$id' => 'col9'],
        ]);

        $this->assertSame('database/db1/collection/col9', $rendered);
    }

    public function testRenderFallsBackToResponseForUnknownNamespace(): void
    {
        $rendered = Parser::render('thing/{unknown.id}', [
            'response' => ['id' => 'r1'],
        ]);

        $this->assertSame('thing/r1', $rendered);
    }

    public function testRenderSupportsUserAndProjectNamespaces(): void
    {
        $rendered = Parser::render('user/{user.$id}/project/{project.$id}', [
            'user' => ['$id' => 'usr1'],
            'project' => new Document(['$id' => 'prj1']),
        ]);

        $this->assertSame('user/usr1/project/prj1', $rendered);
    }

    public function testRenderLeavesUnresolvedTokensIntact(): void
    {
        $rendered = Parser::render('database/{request.databaseId}', [
            'request' => [],
        ]);

        $this->assertSame('database/{request.databaseId}', $rendered);
    }

    public function testRenderShortCircuitsWhenNoTemplate(): void
    {
        $this->assertSame('database/db1', Parser::render('database/db1', []));
        $this->assertSame('', Parser::render('', []));
    }

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
