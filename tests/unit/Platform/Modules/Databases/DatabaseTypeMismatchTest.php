<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases;

use Appwrite\Platform\Modules\Databases\Http\Databases\Action;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;

final class DatabaseTypeMismatchTest extends TestCase
{
    public function testTablesdbAllowsTablesdbAndLegacyButRejectsOtherProducts(): void
    {
        $action = new class () extends Action {
            public static function getName(): string
            {
                return 'testTablesdbTypeGuard';
            }

            public function exposeMismatch(Document $database): bool
            {
                return $this->isDatabaseTypeMismatch($database);
            }

            /** @return string[] */
            public function exposeAllowedTypes(): array
            {
                return $this->getAllowedDatabaseTypes();
            }
        };
        $action->setHttpPath('/v1/tablesdb/:databaseId/tables');

        // TablesDB is the compatibility successor to the legacy databases API:
        // the allowed set must equal what listTablesDatabases advertises.
        $this->assertSame([DATABASE_TYPE_TABLESDB, DATABASE_TYPE_LEGACY], $action->exposeAllowedTypes());

        $this->assertFalse($action->exposeMismatch(new Document(['type' => DATABASE_TYPE_TABLESDB])));
        $this->assertFalse($action->exposeMismatch(new Document(['type' => DATABASE_TYPE_LEGACY])));
        $this->assertTrue($action->exposeMismatch(new Document(['type' => DATABASE_TYPE_DOCUMENTSDB])));
        $this->assertTrue($action->exposeMismatch(new Document(['type' => DATABASE_TYPE_VECTORSDB])));
        // Databases always carry a non-null type (V23 backfilled old rows to
        // legacy); an unexpected empty type is excluded by both the list and guard.
        $this->assertTrue($action->exposeMismatch(new Document([])));
    }

    public function testDocumentsAndVectorsPathsScopeStrictlyToTheirOwnType(): void
    {
        $documents = new class () extends Action {
            public static function getName(): string
            {
                return 'testDocumentsdbTypeGuard';
            }

            public function exposeMismatch(Document $database): bool
            {
                return $this->isDatabaseTypeMismatch($database);
            }
        };
        $documents->setHttpPath('/v1/documentsdb/:databaseId/collections');
        $this->assertFalse($documents->exposeMismatch(new Document(['type' => DATABASE_TYPE_DOCUMENTSDB])));
        $this->assertTrue($documents->exposeMismatch(new Document(['type' => DATABASE_TYPE_VECTORSDB])));
        $this->assertTrue($documents->exposeMismatch(new Document(['type' => DATABASE_TYPE_LEGACY])));
        $this->assertTrue($documents->exposeMismatch(new Document([])));

        $vectors = new class () extends Action {
            public static function getName(): string
            {
                return 'testVectorsdbTypeGuard';
            }

            public function exposeMismatch(Document $database): bool
            {
                return $this->isDatabaseTypeMismatch($database);
            }
        };
        $vectors->setHttpPath('/v1/vectorsdb/:databaseId/collections');
        $this->assertFalse($vectors->exposeMismatch(new Document(['type' => DATABASE_TYPE_VECTORSDB])));
        $this->assertTrue($vectors->exposeMismatch(new Document(['type' => DATABASE_TYPE_DOCUMENTSDB])));
        $this->assertTrue($vectors->exposeMismatch(new Document(['type' => DATABASE_TYPE_LEGACY])));
    }

    public function testLegacyDatabasesPathIsNeverTypeGuarded(): void
    {
        $legacy = new class () extends Action {
            public static function getName(): string
            {
                return 'testLegacyTypeGuard';
            }

            public function exposeMismatch(Document $database): bool
            {
                return $this->isDatabaseTypeMismatch($database);
            }
        };
        $legacy->setHttpPath('/v1/databases/:databaseId/collections');
        $this->assertFalse($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_LEGACY])));
        $this->assertFalse($legacy->exposeMismatch(new Document([])));
        $this->assertFalse($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_TABLESDB])));
        $this->assertFalse($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_DOCUMENTSDB])));
    }
}
