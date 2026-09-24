<?php

namespace Tests\Query\Classifier;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Utopia\Query\Classifier\MongoDB;
use Utopia\Query\Type;

class MongoDBTest extends TestCase
{
    protected MongoDB $classifier;

    protected function setUp(): void
    {
        $this->classifier = new MongoDB();
    }

    /**
     * Build a MongoDB OP_MSG packet with a BSON command document
     *
     * @param  array<string, mixed>  $document  Command document
     */
    private function buildOpMsg(array $document): string
    {
        $bson = $this->encodeBsonDocument($document);

        $sectionKind = "\x00"; // kind 0 = body
        $flags = \pack('V', 0);

        // Header: length (4) + requestId (4) + responseTo (4) + opcode (4)
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))  // message length
            . \pack('V', 1)                          // request ID
            . \pack('V', 0)                          // response to
            . \pack('V', 2013);                      // opcode: OP_MSG

        return $header . $body;
    }

    /**
     * Encode a simple BSON document (supports string, int, bool, and nested documents)
     *
     * @param  array<string, mixed>  $doc
     */
    private function encodeBsonDocument(array $doc): string
    {
        $body = '';

        foreach ($doc as $key => $value) {
            if (\is_string($value)) {
                // Type 0x02: string
                $body .= "\x02" . $key . "\x00" . \pack('V', \strlen($value) + 1) . $value . "\x00";
            } elseif (\is_int($value)) {
                // Type 0x10: int32
                $body .= "\x10" . $key . "\x00" . \pack('V', $value);
            } elseif (\is_bool($value)) {
                // Type 0x08: boolean
                $body .= "\x08" . $key . "\x00" . ($value ? "\x01" : "\x00");
            } elseif (\is_array($value)) {
                // Type 0x03: embedded document
                /** @var array<string, mixed> $value */
                $body .= "\x03" . $key . "\x00" . $this->encodeBsonDocument($value);
            }
        }

        $body .= "\x00"; // terminator

        return \pack('V', 4 + \strlen($body)) . $body;
    }

    // -- Read Commands --

    public function testFindCommand(): void
    {
        $data = $this->buildOpMsg(['find' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testAggregateCommand(): void
    {
        $data = $this->buildOpMsg(['aggregate' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testCountCommand(): void
    {
        $data = $this->buildOpMsg(['count' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testDistinctCommand(): void
    {
        $data = $this->buildOpMsg(['distinct' => 'users', 'key' => 'name', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testListCollectionsCommand(): void
    {
        $data = $this->buildOpMsg(['listCollections' => 1, '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testListDatabasesCommand(): void
    {
        $data = $this->buildOpMsg(['listDatabases' => 1, '$db' => 'admin']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testListIndexesCommand(): void
    {
        $data = $this->buildOpMsg(['listIndexes' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testDbStatsCommand(): void
    {
        $data = $this->buildOpMsg(['dbStats' => 1, '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testCollStatsCommand(): void
    {
        $data = $this->buildOpMsg(['collStats' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testExplainCommand(): void
    {
        $data = $this->buildOpMsg(['explain' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testGetMoreCommand(): void
    {
        $data = $this->buildOpMsg(['getMore' => 12345, '$db' => 'mydb']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testServerStatusCommand(): void
    {
        $data = $this->buildOpMsg(['serverStatus' => 1, '$db' => 'admin']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testPingCommand(): void
    {
        $data = $this->buildOpMsg(['ping' => 1, '$db' => 'admin']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testHelloCommand(): void
    {
        $data = $this->buildOpMsg(['hello' => 1, '$db' => 'admin']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testIsMasterCommand(): void
    {
        $data = $this->buildOpMsg(['isMaster' => 1, '$db' => 'admin']);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    // -- Write Commands --

    public function testInsertCommand(): void
    {
        $data = $this->buildOpMsg(['insert' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testUpdateCommand(): void
    {
        $data = $this->buildOpMsg(['update' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testDeleteCommand(): void
    {
        $data = $this->buildOpMsg(['delete' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testFindAndModifyCommand(): void
    {
        $data = $this->buildOpMsg(['findAndModify' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testCreateCommand(): void
    {
        $data = $this->buildOpMsg(['create' => 'new_collection', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testDropCommand(): void
    {
        $data = $this->buildOpMsg(['drop' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testCreateIndexesCommand(): void
    {
        $data = $this->buildOpMsg(['createIndexes' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testDropIndexesCommand(): void
    {
        $data = $this->buildOpMsg(['dropIndexes' => 'users', '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testDropDatabaseCommand(): void
    {
        $data = $this->buildOpMsg(['dropDatabase' => 1, '$db' => 'mydb']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    public function testRenameCollectionCommand(): void
    {
        $data = $this->buildOpMsg(['renameCollection' => 'users', '$db' => 'admin']);
        $this->assertSame(Type::Write, $this->classifier->classify($data));
    }

    // -- Transaction Commands --

    public function testStartTransaction(): void
    {
        $data = $this->buildOpMsg(['find' => 'users', '$db' => 'mydb', 'startTransaction' => true]);
        $this->assertSame(Type::TransactionBegin, $this->classifier->classify($data));
    }

    public function testCommitTransaction(): void
    {
        $data = $this->buildOpMsg(['commitTransaction' => 1, '$db' => 'admin']);
        $this->assertSame(Type::TransactionEnd, $this->classifier->classify($data));
    }

    public function testAbortTransaction(): void
    {
        $data = $this->buildOpMsg(['abortTransaction' => 1, '$db' => 'admin']);
        $this->assertSame(Type::TransactionEnd, $this->classifier->classify($data));
    }

    public function testStartTransactionFlagOnNonEligibleCommandIsIgnored(): void
    {
        // Only CRUD/aggregate commands can piggy-back startTransaction. A
        // rogue `ping` carrying `startTransaction: true` must NOT classify
        // as TransactionBegin — ping is not transaction-eligible, and the
        // parser skips the scan entirely on the hot path.
        $data = $this->buildOpMsg(['ping' => 1, '$db' => 'admin', 'startTransaction' => true]);
        $this->assertSame(Type::Read, $this->classifier->classify($data));
    }

    public function testStartTransactionFlagOnEligibleCommands(): void
    {
        foreach (['find', 'insert', 'update', 'delete', 'aggregate', 'findAndModify'] as $command) {
            $data = $this->buildOpMsg([$command => 'users', '$db' => 'mydb', 'startTransaction' => true]);
            $this->assertSame(
                Type::TransactionBegin,
                $this->classifier->classify($data),
                \sprintf('Command %s with startTransaction should be TransactionBegin', $command),
            );
        }
    }

    // -- Edge Cases --

    public function testTooShortPacket(): void
    {
        $this->assertSame(Type::Unknown, $this->classifier->classify("\x00\x00\x00\x00"));
    }

    public function testWrongOpcode(): void
    {
        // Build a packet with opcode 2004 (OP_QUERY, legacy) instead of 2013
        $bson = $this->encodeBsonDocument(['find' => 'users']);
        $body = \pack('V', 0) . "\x00" . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2004); // wrong opcode

        $this->assertSame(Type::Unknown, $this->classifier->classify($header . $body));
    }

    public function testUnknownCommand(): void
    {
        $data = $this->buildOpMsg(['customCommand' => 1, '$db' => 'mydb']);
        $this->assertSame(Type::Unknown, $this->classifier->classify($data));
    }

    public function testEmptyBsonDocument(): void
    {
        // OP_MSG with an empty BSON document (just 5 bytes: length + terminator)
        $bson = \pack('V', 5) . "\x00";
        $body = \pack('V', 0) . "\x00" . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $this->assertSame(Type::Unknown, $this->classifier->classify($header . $body));
    }

    public function testMalformedBsonStringLengthDoesNotCrash(): void
    {
        // Build a BSON doc with a string element whose declared strLen is
        // 0xFFFFFFFF (maximum uint32). On 32-bit PHP this is a negative int;
        // on 64-bit it vastly exceeds the buffer. Either way, skipBsonString
        // must reject it and the parser must not read past the buffer.
        // Layout: [docLen][0x02 "foo" \0 0xFFFFFFFF "x" \0][00]
        $malicious = "\x02" . 'foo' . "\x00"
            . "\xFF\xFF\xFF\xFF"   // claimed strLen (huge / negative)
            . 'x' . "\x00";         // some placeholder bytes; we will not read them
        $bsonBody = $malicious . "\x00"; // document terminator
        $bson = \pack('V', 4 + \strlen($bsonBody)) . $bsonBody;

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        // The hasBsonKey scan for startTransaction must bail safely
        // (returning false), and the first-key command lookup is 'foo',
        // which is unknown — so classification is Unknown.
        $this->assertSame(Type::Unknown, $this->classifier->classify($data));
    }

    public function testMalformedBsonBinaryLengthDoesNotCrash(): void
    {
        // Same attack but via a binary element (type 0x05).
        $malicious = "\x05" . 'foo' . "\x00"
            . "\xFF\xFF\xFF\xFF"   // claimed binLen
            . "\x00";               // subtype byte
        $bsonBody = $malicious . "\x00";
        $bson = \pack('V', 4 + \strlen($bsonBody)) . $bsonBody;

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        $this->assertSame(Type::Unknown, $this->classifier->classify($data));
    }

    public function testMalformedBsonNestedDocumentLengthDoesNotCrash(): void
    {
        // Attack via a nested document (type 0x03) whose declared docLen
        // far exceeds the remaining buffer. skipBsonDocument must reject
        // by returning false; any out-of-bounds string index access would
        // raise a warning, promoted to exception by withStrictErrors().
        // Layout: [outer docLen][0x03 "sub" \0 <fake docLen 0x7FFFFFFF> ...][00]
        $malicious = "\x03" . 'sub' . "\x00"
            . "\xFF\xFF\xFF\x7F"   // claimed nested docLen ~ 2GB
            . "\x00\x00\x00\x00\x00";
        $bsonBody = $malicious . "\x00";
        $bson = \pack('V', 4 + \strlen($bsonBody)) . $bsonBody;

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        $result = $this->withStrictErrors(fn () => $this->classifier->classify($data));
        $this->assertSame(Type::Unknown, $result);
    }

    public function testMalformedBsonOuterDocumentLengthDoesNotCrash(): void
    {
        // Outer BSON document claims a length larger than the packet.
        // hasBsonKey must reject before scanning.
        $bsonBody = "\x10" . 'find' . "\x00" . \pack('V', 1) . "\x00";
        // Declare a docLen far larger than actual content.
        $bson = \pack('V', 0x7FFFFFFF) . $bsonBody;

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        // Both hasBsonKey and extractFirstBsonKey must reject the out-of-bounds
        // docLen before scanning keys. With no valid classification available,
        // the parser returns Unknown. The important guarantee is no crash /
        // out-of-bounds read, so we run under strict error handling.
        $result = $this->withStrictErrors(fn () => $this->classifier->classify($data));
        $this->assertSame(Type::Unknown, $result);
    }

    public function testMalformedBsonRegexRunsToEofWithoutCrash(): void
    {
        // A regex element (type 0x0B) with no null terminator for either
        // cstring. skipBsonRegex must return false, not run off the end.
        $malicious = "\x0B" . 'rx' . "\x00"
            . 'pattern-without-null-terminator-at-all';
        $bsonBody = $malicious; // no trailing doc terminator
        $bson = \pack('V', 4 + \strlen($bsonBody) + 1) . $bsonBody . "\x00";

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        // No crash; first key 'rx' is unknown → Unknown.
        $result = $this->withStrictErrors(fn () => $this->classifier->classify($data));
        $this->assertSame(Type::Unknown, $result);
    }

    public function testMalformedBsonDbPointerLengthDoesNotCrash(): void
    {
        // DBPointer (0x0C) = string + 12-byte ObjectId. The string part
        // is valid, but there aren't 12 bytes after it for the ObjectId.
        // skipBsonDbPointer must reject via the new advance() bound check.
        $malicious = "\x0C" . 'ref' . "\x00"
            . \pack('V', 2) . 'a' . "\x00"  // tiny valid string
            . "\x01\x02\x03";               // only 3 bytes, not 12
        $bsonBody = $malicious;
        $bson = \pack('V', 4 + \strlen($bsonBody) + 1) . $bsonBody . "\x00";

        $sectionKind = "\x00";
        $flags = \pack('V', 0);
        $body = $flags . $sectionKind . $bson;
        $header = \pack('V', 16 + \strlen($body))
            . \pack('V', 1)
            . \pack('V', 0)
            . \pack('V', 2013);

        $data = $header . $body;

        // First key 'ref' is unknown → Unknown. Important: no crash while
        // hasBsonKey walks the malformed DBPointer.
        $result = $this->withStrictErrors(fn () => $this->classifier->classify($data));
        $this->assertSame(Type::Unknown, $result);
    }

    /**
     * Run a parser call with PHP warnings/notices promoted to exceptions.
     *
     * Out-of-bounds string index access in PHP emits a warning rather than
     * failing hard. To prove the parser never reads past the buffer we run
     * the call under a custom error handler that throws on any warning.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withStrictErrors(callable $callback): mixed
    {
        \set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            \restore_error_handler();
        }
    }

    public function testDoesNotExposeSqlHelpers(): void
    {
        $methods = \get_class_methods($this->classifier);

        $this->assertNotContains('classifySQL', $methods);
        $this->assertNotContains('extractKeyword', $methods);
    }

    // -- Performance --

    #[Group('performance')]
    public function testParsePerformance(): void
    {
        if (\getenv('CI') !== false) {
            $this->markTestSkipped('Performance targets assume dedicated hardware; CI runners are too variable.');
        }

        $data = $this->buildOpMsg(['find' => 'users', '$db' => 'mydb']);
        $iterations = 100_000;

        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->classifier->classify($data);
        }
        $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $perQuery = ($elapsed / $iterations) * 1_000_000;

        $this->assertLessThan(
            2.0,
            $perQuery,
            \sprintf('MongoDB classify took %.3f us/query (target: < 2.0 us)', $perQuery)
        );
    }

    #[Group('performance')]
    public function testTransactionScanPerformance(): void
    {
        if (\getenv('CI') !== false) {
            $this->markTestSkipped('Performance targets assume dedicated hardware; CI runners are too variable.');
        }

        // Document with many keys before startTransaction to test scanning
        $data = $this->buildOpMsg([
            'find' => 'users',
            '$db' => 'mydb',
            'filter' => ['active' => 1],
            'projection' => ['name' => 1],
            'sort' => ['created' => 1],
            'startTransaction' => true,
        ]);
        $iterations = 100_000;

        $start = \hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $this->classifier->classify($data);
        }
        $elapsed = (\hrtime(true) - $start) / 1_000_000_000;
        $perQuery = ($elapsed / $iterations) * 1_000_000;

        $this->assertLessThan(
            5.0,
            $perQuery,
            \sprintf('MongoDB transaction scan took %.3f us/query (target: < 5.0 us)', $perQuery)
        );
    }
}
