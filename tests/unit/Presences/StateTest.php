<?php

namespace Tests\Unit\Presences;

use Appwrite\Presences\State;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Adapter;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

final class StateTest extends TestCase
{
    public function testNativeUpsertResolvesPresenceByUniqueUserId(): void
    {
        $this->assertUpsertResolvesPresenceByUniqueUserId(true);
    }

    public function testTransactionalUpsertResolvesPresenceByUniqueUserId(): void
    {
        $this->assertUpsertResolvesPresenceByUniqueUserId(false);
    }

    private function assertUpsertResolvesPresenceByUniqueUserId(bool $nativeUpsert): void
    {
        $adapter = $this->createStub(Adapter::class);
        $adapter
            ->method('getSupportForUpsertOnUniqueIndex')
            ->willReturn($nativeUpsert);

        $database = $this->createMock(Database::class);
        $database
            ->method('getAdapter')
            ->willReturn($adapter);
        $database
            ->expects($this->once())
            ->method('findOne')
            ->with(
                State::COLLECTION_ID,
                $this->callback(function (array $queries): bool {
                    return \count($queries) === 1
                        && $queries[0] instanceof Query
                        && $queries[0]->getAttribute() === 'userId'
                        && $queries[0]->getValues() === ['user-id'];
                })
            )
            ->willReturn(new Document(['$id' => 'canonical-presence-id']));

        if ($nativeUpsert) {
            $database
                ->expects($this->once())
                ->method('upsertDocument')
                ->willReturnCallback(fn (string $collection, Document $document) => $document);
        } else {
            $database
                ->expects($this->once())
                ->method('withTransaction')
                ->willReturnCallback(fn (callable $callback) => $callback());
            $database
                ->expects($this->once())
                ->method('getDocument')
                ->with(State::COLLECTION_ID, 'canonical-presence-id', [], true)
                ->willReturn(new Document(['$id' => 'canonical-presence-id']));
            $database
                ->expects($this->once())
                ->method('updateDocument')
                ->willReturnCallback(fn (string $collection, string $id, Document $document) => $document);
        }

        $presence = (new State())->upsertForUser(
            $database,
            new Document([
                'userId' => 'user-id',
                'userInternalId' => 42,
            ]),
            'requested-presence-id',
            'user-id'
        );

        $this->assertSame('canonical-presence-id', $presence->getId());
    }
}
