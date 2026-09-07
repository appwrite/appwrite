<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\VectorsDB\Collections\Indexes\XList;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class IndexesListTest extends TestCase
{
    private const string DATABASE_ID = 'rv109a-vdb-ded';
    private const string COLLECTION_ID = 'vectors';

    /**
     * Index metadata rows are keyed by the database and collection sequences, and a
     * recreated database keeps its id while taking a new sequence. Listing by the id
     * strings therefore surfaces every earlier incarnation's rows next to the live
     * ones; a stale `processing` row from a torn-down incarnation then reads as an
     * index that never became available (r30 PR-95 on rv109a-vdb-ded).
     */
    public function testListsOnlyTheLiveIncarnationOfARecreatedDatabase(): void
    {
        $database = new Document([
            '$id' => self::DATABASE_ID,
            '$sequence' => '148569',
            'type' => DATABASE_TYPE_VECTORSDB,
        ]);
        $collection = new Document([
            '$id' => self::COLLECTION_ID,
            '$sequence' => '1',
            'attributes' => [],
            'indexes' => [],
        ]);
        $records = [
            $this->index('148001', 'processing'),
            $this->index('148569', 'available'),
        ];

        $matching = static fn (array $queries): array => \array_values(\array_filter(
            $records,
            static fn (Document $record): bool => self::satisfies($record, $queries),
        ));

        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collectionId, string $id): Document => match ($collectionId) {
                'databases' => $database,
                'database_148569' => $collection,
                default => new Document(),
            }
        );
        $dbForProject->method('find')->willReturnCallback(
            static fn (string $collectionId, array $queries): array => $matching($queries)
        );
        $dbForProject->method('count')->willReturnCallback(
            static fn (string $collectionId, array $queries): int => \count($matching($queries))
        );

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $listed = null;
        $response = $this->createMock(Response::class);
        $response->expects($this->once())
            ->method('dynamic')
            ->willReturnCallback(static function (Document $document) use (&$listed): void {
                $listed = $document;
            });

        (new XList())->action(self::DATABASE_ID, self::COLLECTION_ID, [], true, $response, $dbForProject, $authorization);

        $this->assertInstanceOf(Document::class, $listed);
        $this->assertSame(1, $listed->getAttribute('total'), 'total must count only the live incarnation');
        $this->assertSame(
            ['148569_1_idx_embeddings'],
            \array_map(static fn (Document $index): string => $index->getId(), $listed->getAttribute('indexes')),
            'a torn-down incarnation of the same database id must not be listed'
        );
    }

    private function index(string $databaseSequence, string $status): Document
    {
        return new Document([
            '$id' => $databaseSequence . '_1_idx_embeddings',
            'key' => 'idx_embeddings',
            'status' => $status,
            'databaseInternalId' => $databaseSequence,
            'databaseId' => self::DATABASE_ID,
            'collectionInternalId' => '1',
            'collectionId' => self::COLLECTION_ID,
            'type' => 'hnsw_cosine',
            'attributes' => ['embeddings'],
        ]);
    }

    /**
     * @param array<Query> $queries
     */
    private static function satisfies(Document $record, array $queries): bool
    {
        foreach ($queries as $query) {
            if ($query->getMethod() !== Query::TYPE_EQUAL) {
                continue;
            }
            if (!\in_array($record->getAttribute($query->getAttribute()), $query->getValues(), true)) {
                return false;
            }
        }

        return true;
    }
}
