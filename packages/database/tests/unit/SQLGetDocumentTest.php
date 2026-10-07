<?php

namespace Tests\Unit;

use Exception;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Utopia\Database\Adapter\MySQL;
use Utopia\Database\Adapter\Postgres;
use Utopia\Database\Document;
use Utopia\Database\Exception\Timeout as TimeoutException;

final class SQLGetDocumentTest extends TestCase
{
    public function testTranslatesExecuteTimeoutClosesCursorAndPreservesOriginalWhenCleanupFails(): void
    {
        $exception = $this->createTimeoutException();

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->getMock();
        $statement->expects($this->once())
            ->method('bindValue')
            ->with(':_uid', 'document')
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('execute')
            ->willThrowException($exception);
        $statement->expects($this->never())
            ->method('fetchAll');
        $statement->expects($this->once())
            ->method('closeCursor')
            ->willThrowException(new PDOException('Failed to close cursor'));

        $this->assertTimeout($this->createMySQL($statement), $exception);
    }

    public function testTranslatesFetchTimeoutAndClosesCursor(): void
    {
        $exception = $this->createTimeoutException();

        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->getMock();
        $statement->expects($this->once())
            ->method('bindValue')
            ->with(':_uid', 'document')
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('fetchAll')
            ->willThrowException($exception);
        $statement->expects($this->once())
            ->method('closeCursor')
            ->willReturn(true);

        $this->assertTimeout($this->createMySQL($statement), $exception);
    }

    public function testUsesPostgresExecuteHook(): void
    {
        $statement = $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->getMock();
        $statement->expects($this->once())
            ->method('bindValue')
            ->with(':_uid', 'document')
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('execute')
            ->willReturn(true);
        $statement->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);
        $statement->expects($this->once())
            ->method('closeCursor')
            ->willReturn(true);

        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);
        $pdo->expects($this->exactly(2))
            ->method('exec')
            ->withConsecutive(
                ["SET statement_timeout = '25ms'"],
                ['RESET statement_timeout']
            )
            ->willReturnOnConsecutiveCalls(0, 0);

        $adapter = new Postgres($pdo);
        $adapter->setDatabase('database');
        $adapter->setNamespace('namespace');
        $adapter->setTimeout(25);

        $document = $adapter->getDocument(
            new Document(['$id' => 'collection']),
            'document'
        );

        $this->assertSame([], $document->getArrayCopy());
    }

    private function assertTimeout(MySQL $adapter, PDOException $exception): void
    {
        try {
            $adapter->getDocument(
                new Document(['$id' => 'collection']),
                'document'
            );
        } catch (TimeoutException $timeout) {
            $this->assertSame($exception, $timeout->getPrevious());

            return;
        }

        $this->fail('Expected a timeout exception.');
    }

    private function createMySQL(\PDOStatement $statement): MySQL
    {
        $pdo = $this->getMockBuilder(\PDO::class)
            ->disableOriginalConstructor()
            ->getMock();
        $pdo->expects($this->once())
            ->method('prepare')
            ->willReturn($statement);

        $adapter = new MySQL($pdo);
        $adapter->setDatabase('database');
        $adapter->setNamespace('namespace');

        return $adapter;
    }

    private function createTimeoutException(): PDOException
    {
        $exception = new PDOException('Query execution was interrupted');
        $code = new ReflectionProperty(Exception::class, 'code');
        $code->setValue($exception, 'HY000');
        $exception->errorInfo = ['HY000', 3024, 'Query execution was interrupted'];

        return $exception;
    }
}
