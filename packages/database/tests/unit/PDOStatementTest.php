<?php

namespace Tests\Unit;

use PDOException;
use PHPUnit\Framework\TestCase;
use Utopia\Database\PDO;
use Utopia\Database\PDOStatement;

class PDOStatementTest extends TestCase
{
    /**
     * @return PDO&\PHPUnit\Framework\MockObject\MockObject
     */
    private function pdoMock(bool $inTransaction): PDO
    {
        $pdo = $this->getMockBuilder(PDO::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['reconnect', 'prepareNative'])
            ->addMethods(['inTransaction'])
            ->getMock();

        $pdo->method('inTransaction')->willReturn($inTransaction);

        return $pdo;
    }

    /**
     * @return \PDOStatement&\PHPUnit\Framework\MockObject\MockObject
     */
    private function statementMock(): \PDOStatement
    {
        return $this->getMockBuilder(\PDOStatement::class)
            ->disableOriginalConstructor()
            ->getMock();
    }

    public function testExecuteReconnectsRePreparesAndReplaysWhenNotInTransaction(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);

        $first = $this->statementMock();
        $first->expects($this->once())
            ->method('bindValue')
            ->with(':id', 'abc', \PDO::PARAM_STR)
            ->willReturn(true);
        $first->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Max connect timeout reached'));

        $second = $this->statementMock();
        $second->expects($this->once())
            ->method('bindValue')
            ->with(':id', 'abc', \PDO::PARAM_STR)
            ->willReturn(true);
        $second->expects($this->once())
            ->method('execute')
            ->willReturn(true);

        $pdo->expects($this->once())->method('reconnect');
        $pdo->expects($this->once())
            ->method('prepareNative')
            ->with('SELECT :id', [])
            ->willReturn($second);

        $statement = new PDOStatement($pdo, $first, 'SELECT :id');
        $statement->bindValue(':id', 'abc');

        $this->assertTrue($statement->execute());
    }

    public function testExecuteRethrowsAndDoesNotReconnectInsideTransaction(): void
    {
        $pdo = $this->pdoMock(inTransaction: true);
        $pdo->expects($this->never())->method('reconnect');
        $pdo->expects($this->never())->method('prepareNative');

        $statement = $this->statementMock();
        $statement->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('Max connect timeout reached'));

        $wrapper = new PDOStatement($pdo, $statement, 'SELECT 1');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Max connect timeout reached');

        $wrapper->execute();
    }

    public function testExecuteRethrowsNonConnectionErrors(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);
        $pdo->expects($this->never())->method('reconnect');
        $pdo->expects($this->never())->method('prepareNative');

        $statement = $this->statementMock();
        $statement->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('SQLSTATE[42000]: Syntax error'));

        $wrapper = new PDOStatement($pdo, $statement, 'SELECT 1');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Syntax error');

        $wrapper->execute();
    }

    public function testForwardsCallsAndPropertiesToUnderlyingStatement(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);

        $statement = $this->statementMock();
        $statement->expects($this->once())
            ->method('rowCount')
            ->willReturn(7);

        $wrapper = new PDOStatement($pdo, $statement, 'SELECT 1');

        $this->assertSame($statement, $wrapper->getStatement());
        $this->assertSame(7, $wrapper->rowCount());
    }

    public function testIsIterableAndDelegatesIterationToTheStatement(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);
        $statement = $this->statementMock();

        $wrapper = new PDOStatement($pdo, $statement, 'SELECT 1');

        $this->assertInstanceOf(\IteratorAggregate::class, $wrapper);
        $this->assertSame($statement, $wrapper->getIterator());
    }

    public function testDoesNotReconnectForNonExecuteMethods(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);
        $pdo->expects($this->never())->method('reconnect');
        $pdo->expects($this->never())->method('prepareNative');

        $statement = $this->statementMock();
        $statement->expects($this->once())
            ->method('fetch')
            ->willThrowException(new PDOException('server has gone away'));

        $wrapper = new PDOStatement($pdo, $statement, 'SELECT 1');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('server has gone away');

        $wrapper->fetch();
    }

    public function testBindParamReplaysCurrentValueAfterReconnect(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);

        $first = $this->statementMock();
        $first->method('bindParam')->willReturn(true);
        $first->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('server has gone away'));

        $replayed = null;
        $second = $this->statementMock();
        $second->expects($this->once())
            ->method('bindParam')
            ->willReturnCallback(function (int|string $param, mixed &$variable) use (&$replayed): bool {
                $replayed = $variable;
                return true;
            });
        $second->expects($this->once())->method('execute')->willReturn(true);

        $pdo->expects($this->once())->method('reconnect');
        $pdo->expects($this->once())->method('prepareNative')->willReturn($second);

        $wrapper = new PDOStatement($pdo, $first, 'SELECT :id');

        $value = 'old';
        $wrapper->bindParam(':id', $value);
        $value = 'new';

        $this->assertTrue($wrapper->execute());
        $this->assertSame('new', $replayed, 'reconnect must replay the value bound by reference at execute time');
    }

    public function testReplaysMixedBindingsInOriginalCallOrder(): void
    {
        $pdo = $this->pdoMock(inTransaction: false);

        $first = $this->statementMock();
        $first->method('bindValue')->willReturn(true);
        $first->method('bindParam')->willReturn(true);
        $first->expects($this->once())
            ->method('execute')
            ->willThrowException(new PDOException('server has gone away'));

        $replay = [];
        $second = $this->statementMock();
        $second->method('bindValue')->willReturnCallback(function (int|string $p, mixed $v) use (&$replay): bool {
            $replay[] = "value:{$v}";
            return true;
        });
        $second->method('bindParam')->willReturnCallback(function (int|string $p, mixed &$v) use (&$replay): bool {
            $replay[] = "param:{$v}";
            return true;
        });
        $second->expects($this->once())->method('execute')->willReturn(true);

        $pdo->expects($this->once())->method('reconnect');
        $pdo->expects($this->once())->method('prepareNative')->willReturn($second);

        $wrapper = new PDOStatement($pdo, $first, 'SELECT :id');

        // Caller rebinds the same placeholder: the later bindParam must win.
        $wrapper->bindValue(':id', 'old');
        $current = 'new';
        $wrapper->bindParam(':id', $current);

        $this->assertTrue($wrapper->execute());
        $this->assertSame(['value:old', 'param:new'], $replay, 'replay must preserve original bind order so the last binding wins');
    }
}
