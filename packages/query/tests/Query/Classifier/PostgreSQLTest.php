<?php

namespace Tests\Query\Classifier;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Classifier\PostgreSQL;
use Utopia\Query\Type;

class PostgreSQLTest extends TestCase
{
    protected PostgreSQL $classifier;

    protected function setUp(): void
    {
        $this->classifier = new PostgreSQL();
    }

    /**
     * Build a PostgreSQL Simple Query ('Q') message
     */
    private function buildQuery(string $sql): string
    {
        $body = $sql . "\x00";
        $length = \strlen($body) + 4;

        return 'Q' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Parse ('P') message
     */
    private function buildParse(string $stmtName, string $sql): string
    {
        $body = $stmtName . "\x00" . $sql . "\x00" . \pack('n', 0);
        $length = \strlen($body) + 4;

        return 'P' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Bind ('B') message
     */
    private function buildBind(): string
    {
        $body = "\x00\x00" . \pack('n', 0) . \pack('n', 0) . \pack('n', 0);
        $length = \strlen($body) + 4;

        return 'B' . \pack('N', $length) . $body;
    }

    /**
     * Build a PostgreSQL Execute ('E') message
     */
    private function buildExecute(): string
    {
        $body = "\x00" . \pack('N', 0);
        $length = \strlen($body) + 4;

        return 'E' . \pack('N', $length) . $body;
    }

    // -- Read Queries --

    public function testSelectQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('SELECT * FROM users WHERE id = 1')));
    }

    public function testSelectLowercase(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('select id, name from users')));
    }

    public function testSelectMixedCase(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('SeLeCt * FROM users')));
    }

    public function testShowQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('SHOW TABLES')));
    }

    public function testDescribeQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('DESCRIBE users')));
    }

    public function testExplainQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('EXPLAIN SELECT * FROM users')));
    }

    public function testTableQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery('TABLE users')));
    }

    public function testValuesQuery(): void
    {
        $this->assertSame(Type::Read, $this->classifier->classify($this->buildQuery("VALUES (1, 'a'), (2, 'b')")));
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
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('DROP TABLE IF EXISTS test')));
    }

    public function testAlterTable(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('ALTER TABLE users ADD COLUMN email TEXT')));
    }

    public function testTruncate(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('TRUNCATE TABLE users')));
    }

    public function testGrant(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('GRANT SELECT ON users TO readonly')));
    }

    public function testRevoke(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('REVOKE ALL ON users FROM public')));
    }

    public function testLockTable(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('LOCK TABLE users IN ACCESS EXCLUSIVE MODE')));
    }

    public function testCall(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery('CALL my_procedure()')));
    }

    public function testDo(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildQuery("DO \$\$ BEGIN RAISE NOTICE 'hello'; END \$\$")));
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

    public function testSavepoint(): void
    {
        $this->assertSame(Type::Transaction, $this->classifier->classify($this->buildQuery('SAVEPOINT sp1')));
    }

    public function testReleaseSavepoint(): void
    {
        $this->assertSame(Type::Transaction, $this->classifier->classify($this->buildQuery('RELEASE SAVEPOINT sp1')));
    }

    public function testSetCommand(): void
    {
        $this->assertSame(Type::Transaction, $this->classifier->classify($this->buildQuery("SET search_path TO 'public'")));
    }

    // -- Extended Query Protocol --

    public function testParseMessageRoutesToWrite(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildParse('stmt1', 'SELECT * FROM users')));
    }

    public function testBindMessageRoutesToWrite(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildBind()));
    }

    public function testExecuteMessageRoutesToWrite(): void
    {
        $this->assertSame(Type::Write, $this->classifier->classify($this->buildExecute()));
    }

    // -- Edge Cases --

    public function testTooShortPacket(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classify('Q'));
    }

    public function testUnknownMessageType(): void
    {
        $data = 'X' . \pack('N', 5) . "\x00";
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
            \sprintf('PostgreSQL parse took %.3f us/query (target: < 1.0 us)', $perQuery)
        );
    }
}
