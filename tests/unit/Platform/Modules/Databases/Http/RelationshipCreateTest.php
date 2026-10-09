<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Event\Event;
use Appwrite\Event\Publisher\Database as DatabasePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Attributes\Relationship\Create;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\RelationshipDeleteAction;
use Utopia\Database\RelationshipType;
use Utopia\Database\Validator\Authorization;

require_once __DIR__ . '/../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

final class RelationshipCreateTest extends TestCase
{
    public function testACollectionMissingFromTheDatabaseIsReportedAsNotFound(): void
    {
        $dbForProject = $this->createStub(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => match ([$collection, $id]) {
                ['databases', 'library'] => new Document(['$id' => 'library', '$sequence' => '7', 'type' => DATABASE_TYPE_LEGACY]),
                ['database_7', 'books'] => new Document(['$id' => 'books', '$sequence' => '3']),
                default => new Document(),
            }
        );

        $adapter = $this->createStub(Adapter::class);
        $adapter->method('hasFeature')->willReturn(true);

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->method('getAdapter')->willReturn($adapter);
        $dbForDatabases->expects($this->once())
            ->method('findCollection')
            ->with('database_7_collection_3')
            ->willReturn(null);

        $authorization = $this->createStub(Authorization::class);
        $authorization->method('skip')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        try {
            (new Create())->action(
                'library',
                'books',
                'authors',
                RelationshipType::ManyToOne->value,
                false,
                null,
                null,
                RelationshipDeleteAction::Restrict->value,
                $this->createStub(Response::class),
                $dbForProject,
                static fn (): Database => $dbForDatabases,
                $this->createStub(DatabasePublisher::class),
                $this->createStub(Event::class),
                $authorization,
            );
            $this->fail('A missing collection must be refused');
        } catch (Exception $exception) {
            $this->assertSame(Exception::COLLECTION_NOT_FOUND, $exception->getType());
        }
    }
}
