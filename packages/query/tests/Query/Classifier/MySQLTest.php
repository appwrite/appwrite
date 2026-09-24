<?php

namespace Tests\Query\Classifier;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Classifier\MySQL;
use Utopia\Query\Type;

class MySQLTest extends TestCase
{
    protected MySQL $classifier;

    protected function setUp(): void
    {
        $this->classifier = new MySQL();
    }

    /**
     * Build a MySQL COM_QUERY packet
     */
    private function buildQuery(string $sql): string
    {
        $payloadLen = 1 + \strlen($sql);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x03" . $sql;
    }

    /**
     * Build a MySQL COM_STMT_PREPARE packet
     */
    private function buildStmtPrepare(string $sql): string
    {
        $payloadLen = 1 + \strlen($sql);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x16" . $sql;
    }

    /**
     * Build a MySQL COM_STMT_EXECUTE packet
     */
    private function buildStmtExecute(int $stmtId): string
    {
        $body = \pack('V', $stmtId) . "\x00" . \pack('V', 1);
        $payloadLen = 1 + \strlen($body);
        $header = \pack('V', $payloadLen);
        $header[3] = "\x00";

        return $header . "\x17" . $body;
    }

    // -- Read Queries --

    public function testSelectQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('SELECT * FROM users WHERE id = 1')));
    }

    public function testSelectLowercase(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('select id from users')));
    }

    public function testShowQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('SHOW DATABASES')));
    }

    public function testDescribeQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('DESCRIBE users')));
    }

    public function testDescQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('DESC users')));
    }

    public function testExplainQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('EXPLAIN SELECT * FROM users')));
    }

    // -- Write Queries --

    public function testInsertQuery(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery("INSERT INTO users (name) VALUES ('test')")));
    }

    public function testUpdateQuery(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery("UPDATE users SET name = 'test' WHERE id = 1")));
    }

    public function testDeleteQuery(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('DELETE FROM users WHERE id = 1')));
    }

    public function testCreateTable(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('CREATE TABLE test (id INT PRIMARY KEY)')));
    }

    public function testDropTable(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('DROP TABLE test')));
    }

    public function testAlterTable(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('ALTER TABLE users ADD COLUMN email VARCHAR(255)')));
    }

    public function testTruncate(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('TRUNCATE TABLE users')));
    }

    // -- Transaction Commands --

    public function testBeginTransaction(): void
    {
        $this->assertSame(Type::TransactionBegin, $this->classifier->classify($this->buildQuery('BEGIN')));
    }

    public function testStartTransaction(): void
    {
        $this->assertSame(Type::TransactionBegin, $this->classifier->classify($this->buildQuery('START TRANSACTION')));
    }

    public function testCommit(): void
    {
        $this->assertSame(Type::TransactionEnd, $this->classifier->classify($this->buildQuery('COMMIT')));
    }

    public function testRollback(): void
    {
        $this->assertSame(Type::TransactionEnd, $this->classifier->classify($this->buildQuery('ROLLBACK')));
    }

    public function testSetCommand(): void
    {
        $this->assertSame(Type::Transaction, $this->classifier->classify($this->buildQuery('SET autocommit = 0')));
    }

    // -- Prepared Statement Protocol --

    public function testStmtPrepareRoutesToWrite(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildStmtPrepare('SELECT * FROM users WHERE id = ?')));
    }

    public function testStmtExecuteRoutesToWrite(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildStmtExecute(1)));
    }

    // -- Edge Cases --

    public function testTooShortPacket(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classify("\x00\x00"));
    }

    public function testUnknownCommand(): void
    {
        $header = \pack('V', 1);
        $header[3] = "\x00";
        $data = $header . "\x01"; // COM_QUIT
        $this->assertSame(Type::Unknown, $this->classifier->classify($data));
    }

    // -- Performance --

    #[Group('performance')]
    public function testParsePerformance(): void
    {
        if (\getenv('CI') !== false) {
            $this->markTestSkipped('Performance targets assume dedicated hardware; CI runners are too variable.');
        }

        $data = $this->buildQuery('SELECT * FROM users WHERE id = 1');
        $iterations = 100_000;

        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->classifier->classify($data);
        }
        $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $perQuery = ($elapsed / $iterations) * 1_000_000;

        $this->assertLessThan(
            1.0,
            $perQuery,
            \sprintf('MySQL parse took %.3f us/query (target: < 1.0 us)', $perQuery)
        );
    }
}
