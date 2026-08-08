<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\XList;
use Appwrite\Utopia\Response as UtopiaResponse;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

/**
 * `GET /v1/databases/{id}/collections` on a VectorsDB or DocumentsDB database used to
 * reach the backend and query `database_{sequence}`, a table shaped for a different
 * product, which surfaced as an opaque 500.
 *
 * The refusal has to happen before the backend is touched, so these tests assert the
 * backend is never reached rather than pinning the wording of whatever the engine
 * would have thrown.
 */
final class LegacyCollectionsRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array{reached: bool}
     */
    private function listCollections(array $attributes): array
    {
        $reached = false;

        $dbForProject = $this->createMock(Database::class);
        $dbForProject
            ->method('getDocument')
            ->willReturn(new Document(['$id' => 'db', '$sequence' => '7', ...$attributes]));
        $dbForProject
            ->method('find')
            ->willReturnCallback(function () use (&$reached): array {
                $reached = true;

                return [];
            });
        $dbForProject
            ->method('count')
            ->willReturnCallback(function () use (&$reached): int {
                $reached = true;

                return 0;
            });

        (new XList())->action(
            'db',
            [],
            '',
            false,
            $this->createMock(UtopiaResponse::class),
            $dbForProject,
            new Authorization(),
        );

        return ['reached' => $reached];
    }

    public function testAVectorsDatabaseIsRefusedBeforeTheBackendIsQueried(): void
    {
        try {
            $this->listCollections(['type' => DATABASE_TYPE_VECTORSDB]);
            $this->fail('Listing collections of a VectorsDB database on /v1/databases must be refused, not served.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DATABASE_NOT_FOUND, $exception->getType());
        }
    }

    public function testADocumentsDatabaseIsRefusedBeforeTheBackendIsQueried(): void
    {
        try {
            $this->listCollections(['type' => DATABASE_TYPE_DOCUMENTSDB]);
            $this->fail('Listing collections of a DocumentsDB database on /v1/databases must be refused, not served.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::DATABASE_NOT_FOUND, $exception->getType());
        }
    }

    /**
     * The mismatched call must not reach `database_{sequence}` at all — that query is
     * where the 500 came from.
     */
    public function testTheRefusedCallNeverReachesTheBackend(): void
    {
        foreach ([DATABASE_TYPE_VECTORSDB, DATABASE_TYPE_DOCUMENTSDB] as $type) {
            $reached = true;

            try {
                $reached = $this->listCollections(['type' => $type])['reached'];
            } catch (Exception) {
                $reached = false;
            }

            $this->assertFalse($reached, "a '{$type}' database must be refused before database_{sequence} is queried");
        }
    }

    /**
     * The guard must not over-refuse: the products this path really serves still reach
     * the backend.
     */
    public function testTheServedTypesStillReachTheBackend(): void
    {
        foreach ([DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB] as $type) {
            $this->assertTrue(
                $this->listCollections(['type' => $type])['reached'],
                "a '{$type}' database must still be served by /v1/databases/{id}/collections",
            );
        }
    }
}
