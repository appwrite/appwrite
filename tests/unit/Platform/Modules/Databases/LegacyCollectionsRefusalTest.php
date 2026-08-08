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
    /**
     * Whether the action queried `database_{sequence}`.
     *
     * An instance property rather than a return value, because the call under test
     * throws on the path this suite cares about — a returned flag would be lost with
     * the stack, leaving the assertion to pass on the catch alone.
     */
    private bool $reached = false;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function listCollections(array $attributes): void
    {
        $this->reached = false;

        $dbForProject = $this->createMock(Database::class);
        $dbForProject
            ->method('getDocument')
            ->willReturn(new Document(['$id' => 'db', '$sequence' => '7', ...$attributes]));
        $dbForProject
            ->method('find')
            ->willReturnCallback(function (): array {
                $this->reached = true;

                return [];
            });
        $dbForProject
            ->method('count')
            ->willReturnCallback(function (): int {
                $this->reached = true;

                return 0;
            });

        (new XList())->action(
            'db',
            [],
            '',
            false,
            $this->createStub(UtopiaResponse::class),
            $dbForProject,
            new Authorization(),
        );
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
     * where the 500 came from. Refusing and querying anyway would still 500.
     */
    public function testTheRefusedCallNeverReachesTheBackend(): void
    {
        foreach ([DATABASE_TYPE_VECTORSDB, DATABASE_TYPE_DOCUMENTSDB] as $type) {
            try {
                $this->listCollections(['type' => $type]);
            } catch (Exception) {
                // The refusal is expected here; what it did on the way is the assertion.
            }

            $this->assertFalse($this->reached, "a '{$type}' database must be refused before database_{sequence} is queried");
        }
    }

    /**
     * The guard must not over-refuse: the products this path really serves still reach
     * the backend.
     */
    public function testTheServedTypesStillReachTheBackend(): void
    {
        foreach ([DATABASE_TYPE_LEGACY, DATABASE_TYPE_TABLESDB] as $type) {
            $this->listCollections(['type' => $type]);

            $this->assertTrue(
                $this->reached,
                "a '{$type}' database must still be served by /v1/databases/{id}/collections",
            );
        }
    }
}
