<?php

namespace Utopia\Tests\Unit\Sources;

use Appwrite\Models\ColumnList;
use Appwrite\Services\TablesDB;
use PHPUnit\Framework\TestCase;
use Utopia\Migration\Resources\Database\Database;
use Utopia\Migration\Resources\Database\Table;
use Utopia\Migration\Sources\Appwrite\Reader\API;

final class AppwriteReaderApiTest extends TestCase
{
    public function testListColumnsNormalizesSdkListResponse(): void
    {
        $columns = [
            [
                'key' => 'title',
                'type' => 'string',
                'status' => 'available',
                'error' => '',
                'required' => true,
                'array' => false,
                '$createdAt' => '2026-08-12T00:00:00.000+00:00',
                '$updatedAt' => '2026-08-12T00:00:00.000+00:00',
                'size' => 255,
                'default' => null,
                'encrypt' => false,
            ],
        ];

        $database = new Database('database', 'Database');
        $table = new Table($database, 'Table', 'table');

        $tables = $this->createMock(TablesDB::class);
        $tables
            ->expects($this->once())
            ->method('listColumns')
            ->with('database', 'table', [])
            ->willReturn(ColumnList::from([
                'total' => 1,
                'columns' => $columns,
            ]));

        $this->assertSame($columns, (new API($tables))->listColumns($table));
    }
}
