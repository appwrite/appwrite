<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Migrations;

use Appwrite\Platform\Modules\Migrations\Http\Migrations\Action;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Migration\Resource;
use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\CSV;
use Utopia\Migration\Sources\Firebase;
use Utopia\Migration\Sources\JSON;

final class RetryRequirementsTest extends TestCase
{
    /**
     * @param array<string, mixed> $migration
     * @param array{0: array<string>, 1: array<array<string>>} $expected
     */
    #[DataProvider('migrations')]
    public function testRetryDemandsTheCreationGate(array $migration, array $expected): void
    {
        $action = new class () extends Action {
            /**
             * @return array{0: array<string>, 1: array<array<string>>}
             */
            public function requirements(Document $migration): array
            {
                return $this->retryRequirements($migration);
            }
        };

        $this->assertSame($expected, $action->requirements(new Document($migration)));
    }

    /**
     * @return \Iterator<string, array{array<string, mixed>, array{0: array<string>, 1: array<array<string>>}}>
     */
    public static function migrations(): \Iterator
    {
        $rows = [Resource::TYPE_DATABASE, Resource::TYPE_TABLE, Resource::TYPE_ROW];

        yield 'CSV export reads the TablesDB data' => [
            ['source' => Appwrite::getName(), 'destination' => CSV::getName(), 'parentResourceType' => Resource::TYPE_DATABASE, 'resources' => $rows],
            [[], [['rows.read', 'documents.read']]],
        ];
        yield 'JSON export reads the DocumentsDB data' => [
            ['source' => Appwrite::getName(), 'destination' => JSON::getName(), 'parentResourceType' => Resource::TYPE_DATABASE_DOCUMENTSDB, 'resources' => $rows],
            [[], [['documentsdb.documents.read']]],
        ];
        yield 'CSV import writes the VectorsDB data' => [
            ['source' => CSV::getName(), 'destination' => Appwrite::getName(), 'parentResourceType' => Resource::TYPE_DATABASE_VECTORSDB, 'resources' => $rows],
            [[], [['vectorsdb.documents.write']]],
        ];
        yield 'JSON import writes the TablesDB data' => [
            ['source' => JSON::getName(), 'destination' => Appwrite::getName(), 'parentResourceType' => Resource::TYPE_DATABASE, 'resources' => $rows],
            [[], [['rows.write', 'documents.write']]],
        ];
        yield 'Appwrite import checks each resource' => [
            ['source' => Appwrite::getName(), 'destination' => Appwrite::getName(), 'resources' => [Resource::TYPE_USER]],
            [[Resource::TYPE_USER], []],
        ];
        yield 'Firebase import checks each resource' => [
            ['source' => Firebase::getName(), 'destination' => Appwrite::getName(), 'resources' => [Resource::TYPE_DATABASE]],
            [[Resource::TYPE_DATABASE], []],
        ];
    }
}
