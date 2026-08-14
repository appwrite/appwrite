<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases;

use Appwrite\Platform\Modules\Databases\Http\Databases\Action;
use Appwrite\Platform\Modules\Databases\Http\Databases\XList;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Document;
use Utopia\Database\Query;

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

    /**
     * The legacy path is the mirror of the TablesDB one: same product either side of
     * a rename, so it serves both types and refuses the other two.
     *
     * It was previously exempt from the guard entirely, which let it resolve by id
     * what its own list refused to return — `GET /v1/databases/{id}` answered for a
     * DocumentsDB or VectorsDB database that `GET /v1/databases` never listed, and
     * `/collections` on one then 500'd against a table shaped for another product.
     */
    public function testLegacyPathServesTablesdbAndRefusesTheOtherProducts(): void
    {
        $legacy = $this->legacyAction();

        $this->assertSame([DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB], $legacy->exposeAllowedTypes());

        $this->assertFalse($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_LEGACY])));
        $this->assertFalse($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_TABLESDB])));
        $this->assertTrue($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_DOCUMENTSDB])));
        $this->assertTrue($legacy->exposeMismatch(new Document(['type' => DATABASE_TYPE_VECTORSDB])));
        $this->assertTrue($legacy->exposeMismatch(new Document([])));
    }

    /**
     * The by-id guard and the list filter must select the same set, or the two
     * disagree about which databases exist.
     */
    public function testLegacyListFilterAndByIdGuardSelectTheSameSet(): void
    {
        $legacy = $this->legacyAction();
        $filters = $legacy->exposeQueryFilters();

        $this->assertCount(1, $filters);
        $this->assertSame('type', $filters[0]->getAttribute());

        $listed = $filters[0]->getValues();
        $this->assertSame($legacy->exposeAllowedTypes(), $listed);

        foreach ([DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB, DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB] as $type) {
            $this->assertSame(
                in_array($type, $listed, true),
                !$legacy->exposeMismatch(new Document(['type' => $type])),
                "list and get disagree about whether a '{$type}' database exists on /v1/databases",
            );
        }
    }

    /**
     * The two paths over the same product must accept the same databases, so a caller
     * migrating from /v1/databases to /v1/tablesdb never loses one.
     */
    public function testLegacyAndTablesdbPathsAcceptTheSameDatabases(): void
    {
        $legacy = $this->legacyAction();
        $tablesdb = $this->tablesdbAction();

        foreach ([DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB, DATABASE_TYPE_DOCUMENTSDB, DATABASE_TYPE_VECTORSDB] as $type) {
            $this->assertSame(
                $tablesdb->exposeMismatch(new Document(['type' => $type])),
                $legacy->exposeMismatch(new Document(['type' => $type])),
                "the deprecated and successor paths disagree about a '{$type}' database",
            );
        }
    }

    private function legacyAction(): object
    {
        $legacy = new class () extends XList {
            public static function getName(): string
            {
                return 'testLegacyTypeGuard';
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

            /** @return Query[] */
            public function exposeQueryFilters(): array
            {
                return $this->getDatabaseTypeQueryFilters();
            }
        };
        $legacy->setHttpPath('/v1/databases/:databaseId/collections');

        return $legacy;
    }

    private function tablesdbAction(): object
    {
        $tablesdb = new class () extends Action {
            public static function getName(): string
            {
                return 'testTablesdbMirrorGuard';
            }

            public function exposeMismatch(Document $database): bool
            {
                return $this->isDatabaseTypeMismatch($database);
            }
        };
        $tablesdb->setHttpPath('/v1/tablesdb/:databaseId/tables');

        return $tablesdb;
    }
}
