<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Workers;

use Appwrite\Event\Realtime;
use Appwrite\Platform\Modules\Databases\Workers\Databases;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Logger\Log;
use Utopia\Queue\Message;

require_once __DIR__ . '/../../../../app/init.php';

final class DatabasesTest extends TestCase
{
    /**
     * A dedicated database (tablesdb/documentsdb/vectorsdb) hosts its collections
     * on the backing resolved by getDatabasesDB, not the shared project database.
     * createAttribute must run its DDL on that resolved database — as its siblings
     * createIndex/deleteAttribute already do — or, for a dedicated backing, it
     * queries the shared pool, cannot find the collection and fails
     * "Collection not found" (DAT-1967).
     */
    public function testCreateAttributeTargetsResolvedDatabaseNotProjectDb(): void
    {
        $attribute = new Document([
            '$id' => 'attr1',
            'key' => 'note',
            'type' => Database::VAR_STRING,
            'size' => 64,
            'required' => false,
            'default' => null,
        ]);

        // The shared project DB owns the `attributes` metadata but must never
        // receive the collection DDL for a dedicated backing.
        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => $collection === 'attributes' ? $attribute : new Document()
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);
        $dbForProject->expects($this->never())->method('createAttribute');

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'proj1']));

        // The resolved (dedicated) backing must receive the physical DDL.
        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('createAttribute')
            ->willReturn(true);

        $worker = new class () extends Databases {
            protected function trigger(
                Document $database,
                Document $collection,
                Document $project,
                string $event,
                Realtime $queueForRealtime,
                Document|null $attribute = null,
                Document|null $index = null,
            ): void {
            }
        };

        $message = new Message([
            'pid' => 'pid',
            'queue' => 'v1-databases',
            'timestamp' => \time(),
            'payload' => [
                'type' => DATABASE_TYPE_CREATE_ATTRIBUTE,
                'database' => ['$id' => 'db1', '$sequence' => '30360'],
                'collection' => ['$id' => 'proof', '$sequence' => '1'],
                'document' => ['$id' => 'attr1'],
            ],
        ]);

        $worker->action(
            $message,
            new Document(['$id' => 'proj1']),
            $dbForPlatform,
            $dbForProject,
            static fn () => $dbForDatabases,
            $this->createStub(Realtime::class),
            $this->createStub(Log::class),
        );
    }

    public function testCreateBooleanAttributeWithFalseDefault(): void
    {
        $attribute = new Document([
            '$id' => 'attr_bool',
            'key' => 'hasTrained',
            'type' => Database::VAR_BOOLEAN,
            'size' => 0,
            'required' => false,
            'default' => 'false', // serialized string representation
        ]);

        $dbForProject = $this->createMock(Database::class);
        $dbForProject->method('getDocument')->willReturnCallback(
            fn (string $collection, string $id) => $collection === 'attributes' ? $attribute : new Document()
        );
        $dbForProject->method('updateDocument')->willReturnArgument(2);

        $dbForPlatform = $this->createMock(Database::class);
        $dbForPlatform->method('getDocument')->willReturn(new Document(['$id' => 'proj1']));

        $dbForDatabases = $this->createMock(Database::class);
        $dbForDatabases->expects($this->once())
            ->method('createAttribute')
            ->with(
                $this->anything(),
                $this->equalTo('hasTrained'),
                $this->equalTo(Database::VAR_BOOLEAN),
                $this->anything(),
                $this->equalTo(false),
                $this->identicalTo(false), // MUST be boolean false, not true or null!
            )
            ->willReturn(true);

        $worker = new class () extends Databases {
            protected function trigger(
                Document $database,
                Document $collection,
                Document $project,
                string $event,
                Realtime $queueForRealtime,
                Document|null $attribute = null,
                Document|null $index = null,
            ): void {
            }
        };

        $message = new Message([
            'pid' => 'pid',
            'queue' => 'v1-databases',
            'timestamp' => \time(),
            'payload' => [
                'type' => DATABASE_TYPE_CREATE_ATTRIBUTE,
                'database' => ['$id' => 'db1', '$sequence' => '100'],
                'collection' => ['$id' => 'user', '$sequence' => '1'],
                'document' => ['$id' => 'attr_bool'],
            ],
        ]);

        $worker->action(
            $message,
            new Document(['$id' => 'proj1']),
            $dbForPlatform,
            $dbForProject,
            static fn () => $dbForDatabases,
            $this->createStub(Realtime::class),
            $this->createStub(Log::class),
        );
    }
}
