<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Appwrite\Databases\TransactionState;
use Appwrite\Utopia\Database\Documents\User;
use PDO;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\None;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Attribute;
use Utopia\Database\Collection;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;

final class TransactionStateTest extends TestCase
{
    private const string MOVIES = 'database_1_collection_1';

    private Authorization $authorization;

    private Database $store;

    private TransactionState $state;

    protected function setUp(): void
    {
        $this->authorization = new Authorization();
        $this->authorization->addRole(Role::any()->toString());

        $this->store = new Database(new SQLite(new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])), new Cache(new None()));
        $this->store
            ->setAuthorization($this->authorization)
            ->setDatabase('transactionState')
            ->setNamespace('transaction_state_' . \uniqid());
        $this->store->create();
        $this->store->createCollection(Collection::create(
            id: self::MOVIES,
            attributes: [Attribute::string('genre', size: 32), Attribute::integer('year')],
            permissions: [Permission::read(Role::any()), Permission::create(Role::any())],
        ));
        foreach ([['drama', 2000], ['drama', 2002], ['comedy', 1990]] as [$genre, $year]) {
            $this->store->createDocument(self::MOVIES, new Document(['genre' => $genre, 'year' => $year]));
        }

        $this->state = new TransactionState($this->store, $this->authorization, fn (): Database => $this->store, new User());
    }

    public function testListOutsideATransactionReturnsDocuments(): void
    {
        $documents = $this->state->listDocuments(new Document(), self::MOVIES, null, [Query::equal('genre', ['drama'])]);

        $this->assertCount(2, $documents);
        $this->assertContainsOnlyInstancesOf(Document::class, $documents);
        $this->assertSame(['drama', 'drama'], \array_map(static fn (Document $document): mixed => $document->getAttribute('genre'), $documents));
    }

    public function testListOutsideATransactionRunsAggregates(): void
    {
        $rows = $this->state->listDocuments(new Document(), self::MOVIES, null, [
            Query::count('*', 'movies'),
            Query::sum('year', 'years'),
        ]);

        $this->assertCount(1, $rows);
        $this->assertInstanceOf(Document::class, $rows[0]);
        $this->assertSame(3, $rows[0]->getAttribute('movies'));
        $this->assertSame(5992, $rows[0]->getAttribute('years'));
    }

    public function testListOutsideATransactionRunsGroups(): void
    {
        $rows = $this->state->listDocuments(new Document(), self::MOVIES, null, [
            Query::groupBy(['genre']),
            Query::count('*', 'movies'),
            Query::orderAsc('genre'),
        ]);

        $this->assertSame(
            [['genre' => 'comedy', 'movies' => 1], ['genre' => 'drama', 'movies' => 2]],
            \array_map(static fn (Document $row): array => ['genre' => $row->getAttribute('genre'), 'movies' => $row->getAttribute('movies')], $rows),
        );
    }

    public function testAggregatesInsideATransactionAreRejected(): void
    {
        $this->expectException(QueryException::class);

        $this->state->listDocuments(new Document(), self::MOVIES, 'transaction', [Query::count('*', 'movies')]);
    }

    public function testAggregateCountInsideATransactionIsRejected(): void
    {
        $this->expectException(QueryException::class);

        $this->state->countDocuments(new Document(), self::MOVIES, 'transaction', [Query::groupBy(['genre'])]);
    }
}
