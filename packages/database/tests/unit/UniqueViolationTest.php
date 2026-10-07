<?php

namespace Tests\Unit;

use Exception;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Throwable;
use Utopia\Database\Adapter\Mongo;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Adapter\SQLite;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Unique as UniqueException;

final class UniqueViolationTest extends TestCase
{
    public function testMySQLDocumentIdConflictIsDuplicate(): void
    {
        $this->assertDuplicate(MySQL::class, $this->mysqlException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'movie-1' for key 'movies._uid'"
        ));
    }

    public function testMySQLPrimaryKeyConflictIsDuplicate(): void
    {
        $this->assertDuplicate(MySQL::class, $this->mysqlException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '5' for key 'PRIMARY'"
        ));
    }

    public function testMySQLUniqueIndexConflictWithUidInValueIsUnique(): void
    {
        $this->assertUnique(MySQL::class, $this->mysqlException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'prefix_uid_suffix' for key 'slug'"
        ));
    }

    public function testMySQLUniqueIndexConflictWithUidInIndexNameIsUnique(): void
    {
        $this->assertUnique(MySQL::class, $this->mysqlException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'a' for key 'movies.slug_uid_index'"
        ));
    }

    public function testMySQLUnparsableMessageIsDuplicate(): void
    {
        $this->assertDuplicate(MySQL::class, $this->mysqlException(
            'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'
        ));
    }

    public function testPostgresDocumentIdConflictIsDuplicate(): void
    {
        $this->assertDuplicate(Postgres::class, $this->postgresException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "ns_1_movies_uid"'
            . "\nDETAIL:  Key (_uid, _tenant)=(movie-1, 1) already exists."
        ));
    }

    public function testPostgresUniqueIndexConflictWithUidInValueIsUnique(): void
    {
        $this->assertUnique(Postgres::class, $this->postgresException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "ns_1_movies_slug"'
            . "\nDETAIL:  Key (slug)=(prefix_uid_suffix) already exists."
        ));
    }

    public function testPostgresCompositeIndexOnDocumentIdIsUnique(): void
    {
        $this->assertUnique(Postgres::class, $this->postgresException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "ns_1_movies_pair"'
            . "\nDETAIL:  Key (_uid, email)=(movie-1, a@b.co) already exists."
        ));
    }

    public function testPostgresMissingDetailIsDuplicate(): void
    {
        $this->assertDuplicate(Postgres::class, $this->postgresException(
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "ns_1_movies_uid"'
        ));
    }

    public function testSQLiteDocumentIdConflictIsDuplicate(): void
    {
        $this->assertDuplicate(SQLite::class, $this->sqliteException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ns_movies._tenant, ns_movies._uid'
        ));
    }

    public function testSQLiteCompositeIndexOnDocumentIdIsUnique(): void
    {
        $this->assertUnique(SQLite::class, $this->sqliteException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ns_movies._uid, ns_movies.email'
        ));
    }

    public function testSQLiteUniqueIndexConflictIsUnique(): void
    {
        $this->assertUnique(SQLite::class, $this->sqliteException(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ns_movies.slug'
        ));
    }

    public function testMongoDocumentIdConflictIsDuplicate(): void
    {
        $this->assertDuplicate(Mongo::class, new Exception(
            'E11000 duplicate key error collection: db.ns_movies index: _uid dup key: { _uid: "movie-1" }',
            11000
        ));
    }

    public function testMongoUniqueIndexConflictWithUidInValueIsUnique(): void
    {
        $this->assertUnique(Mongo::class, new Exception(
            'E11000 duplicate key error collection: db.ns_movies index: slug dup key: { slug: "prefix_uid_suffix" }',
            11000
        ));
    }

    public function testMongoUnparsableMessageIsDuplicate(): void
    {
        $this->assertDuplicate(Mongo::class, new Exception('E11000 duplicate key error', 11000));
    }

    private function mysqlException(string $message): PDOException
    {
        $exception = new PDOException($message);
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, '23000');
        $exception->errorInfo = ['23000', 1062, $message];

        return $exception;
    }

    private function postgresException(string $message): PDOException
    {
        $exception = new PDOException($message);
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, '23505');
        $exception->errorInfo = ['23505', 7, $message];

        return $exception;
    }

    private function sqliteException(string $message): PDOException
    {
        $exception = new PDOException($message);
        (new ReflectionProperty(Exception::class, 'code'))->setValue($exception, 'HY000');
        $exception->errorInfo = ['HY000', 19, $message];

        return $exception;
    }

    /**
     * @param class-string $adapter
     */
    private function assertDuplicate(string $adapter, Throwable $exception): void
    {
        $processed = $this->process($adapter, $exception);

        $this->assertInstanceOf(DuplicateException::class, $processed);
        $this->assertNotInstanceOf(UniqueException::class, $processed);
    }

    /**
     * @param class-string $adapter
     */
    private function assertUnique(string $adapter, Throwable $exception): void
    {
        $this->assertInstanceOf(UniqueException::class, $this->process($adapter, $exception));
    }

    /**
     * @param class-string $adapter
     */
    private function process(string $adapter, Throwable $exception): Throwable
    {
        $class = new ReflectionClass($adapter);
        $method = $class->getMethod('processException');

        return $method->invoke($class->newInstanceWithoutConstructor(), $exception);
    }
}
