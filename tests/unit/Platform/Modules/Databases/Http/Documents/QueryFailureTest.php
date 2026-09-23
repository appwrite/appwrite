<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http\Documents;

use Appwrite\Databases\TransactionState;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Get;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\XList;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Exception as QueryLibraryException;
use Utopia\Query\Exception\UnsupportedException;
use Utopia\Query\Exception\ValidationException;

require_once __DIR__ . '/../../../../../../../app/init.php';
require_once __DIR__ . '/../../../../../../../src/Appwrite/Platform/Modules/Databases/Constants.php';

/**
 * Running a query can fail because the caller's query is invalid, which the database library reports with its
 * own query exception (or the query library's validation exception), or because of an internal fault such as a
 * dialect gap or a compiler error, which the query library reports with its base exception. Only the first is
 * the caller's 400; a fault must stay a 5xx so it reaches error reporting instead of blaming the request.
 */
final class QueryFailureTest extends TestCase
{
    private const string DATABASE_ID = 'blog';

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function faults(): iterable
    {
        yield 'a compiler fault' => [new QueryLibraryException('Expected ROW or ROWS at position 12')];
        yield 'a dialect gap' => [new UnsupportedException('Full-text search is not supported by this dialect.')];
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function rejections(): iterable
    {
        yield 'a database library rejection' => [new QueryException('Invalid query: Attribute not found in schema: missing')];
        yield 'a query library validation error' => [new ValidationException('Invalid join operator: LIKE')];
    }

    #[DataProvider('faults')]
    public function testGetDoesNotBlameTheQueryForAFault(\Throwable $fault): void
    {
        $this->assertPropagates($fault, fn () => $this->get($this->failingDatabase('getDocument', $fault)));
    }

    #[DataProvider('rejections')]
    public function testGetReportsARejectedQueryAsInvalid(\Throwable $rejection): void
    {
        $this->assertInvalidQuery($rejection->getMessage(), fn () => $this->get($this->failingDatabase('getDocument', $rejection)));
    }

    #[DataProvider('faults')]
    public function testListDoesNotBlameTheQueryForAFault(\Throwable $fault): void
    {
        $this->assertPropagates($fault, fn () => $this->list($this->failingDatabase('find', $fault)));
    }

    #[DataProvider('faults')]
    public function testListDoesNotBlameTheQueryForACountFault(\Throwable $fault): void
    {
        $this->assertPropagates($fault, fn () => $this->list($this->failingDatabase('count', $fault), includeTotal: true));
    }

    #[DataProvider('rejections')]
    public function testListReportsARejectedQueryAsInvalid(\Throwable $rejection): void
    {
        $this->assertInvalidQuery($rejection->getMessage(), fn () => $this->list($this->failingDatabase('find', $rejection)));
    }

    public function testGetReportsAnUnparsableQueryAsInvalid(): void
    {
        $this->assertInvalidQuery(null, fn () => $this->get($this->failingDatabase('getDocument', new \LogicException('not reached')), ['{"method":"missing"}']));
    }

    public function testListReportsAnUnparsableQueryAsInvalid(): void
    {
        $this->assertInvalidQuery(null, fn () => $this->list($this->failingDatabase('find', new \LogicException('not reached')), queries: ['{"method":"missing"}']));
    }

    /**
     * @param list<string> $queries
     */
    private function get(Database $documents, array $queries = []): void
    {
        (new Get())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'posts',
            documentId: 'post1',
            queries: $queries,
            transactionId: null,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            getDatabasesDB: static fn (): Database => $documents,
            usage: new Context(),
            transactionState: $this->createStub(TransactionState::class),
            authorization: new Authorization(),
            user: new User(['$id' => 'reader']),
        );
    }

    /**
     * @param list<string> $queries
     */
    private function list(Database $documents, bool $includeTotal = false, array $queries = []): void
    {
        (new XList())->action(
            databaseId: self::DATABASE_ID,
            collectionId: 'posts',
            queries: $queries,
            transactionId: null,
            includeTotal: $includeTotal,
            ttl: 0,
            response: $this->createStub(Response::class),
            dbForProject: $this->projectDatabase(),
            user: new User(['$id' => 'reader']),
            getDatabasesDB: static fn (): Database => $documents,
            usage: new Context(),
            transactionState: $this->createStub(TransactionState::class),
            authorization: new Authorization(),
        );
    }

    private function projectDatabase(): Database
    {
        $metadata = [
            'databases' => [
                self::DATABASE_ID => new Document([
                    '$id' => self::DATABASE_ID,
                    '$sequence' => '1',
                    'type' => DATABASE_TYPE_LEGACY,
                    'enabled' => true,
                ]),
            ],
            'database_1' => [
                'posts' => new Document([
                    '$id' => 'posts',
                    '$sequence' => '1',
                    'enabled' => true,
                    'attributes' => [],
                ]),
            ],
        ];

        $database = $this->createStub(Database::class);
        $database->method('getDocument')->willReturnCallback(
            static fn (string $collection, string $id): Document => $metadata[$collection][$id] ?? new Document()
        );

        return $database;
    }

    private function failingDatabase(string $failing, \Throwable $failure): Database
    {
        $database = $this->createStub(Database::class);
        $database->method('skipRelationships')->willReturnCallback(static fn (callable $callback): mixed => $callback());

        foreach (['getDocument' => new Document(), 'find' => [], 'count' => 0] as $method => $result) {
            if ($method === $failing) {
                $database->method($method)->willThrowException($failure);
            } else {
                $database->method($method)->willReturn($result);
            }
        }

        return $database;
    }

    private function assertPropagates(\Throwable $fault, callable $read): void
    {
        try {
            $read();
        } catch (\Throwable $thrown) {
            $this->assertSame($fault, $thrown, 'an internal query fault must reach the error handler unchanged, which answers it with a 5xx');

            return;
        }

        $this->fail('The query fault was swallowed');
    }

    private function assertInvalidQuery(?string $message, callable $read): void
    {
        try {
            $read();
        } catch (Exception $error) {
            $this->assertSame(Exception::GENERAL_QUERY_INVALID, $error->getType());
            $this->assertSame(400, $error->getCode());
            if ($message !== null) {
                $this->assertSame($message, $error->getMessage());
            }

            return;
        }

        $this->fail('A rejected query must be a 400 ' . Exception::GENERAL_QUERY_INVALID);
    }
}
