<?php

namespace Utopia\Query\Classifier;

use Utopia\Query\Classifier;
use Utopia\Query\Type;

/**
 * MongoDB Wire Protocol Parser
 *
 * Parses MongoDB OP_MSG (opcode 2013) messages and classifies commands.
 *
 * OP_MSG format:
 * - Bytes 0-3: Message length (little-endian int32)
 * - Bytes 4-7: Request ID
 * - Bytes 8-11: Response To
 * - Bytes 12-15: Opcode (2013 = OP_MSG)
 * - Bytes 16-19: Flag bits
 * - Byte 20: Section kind (0 = body)
 * - Bytes 21+: BSON document
 *
 * The first key in the BSON body document is the command name.
 * Classification is based on the command name:
 * - Read: find, aggregate, count, distinct, listCollections, etc.
 * - Write: insert, update, delete, create, drop, createIndexes, etc.
 * - TransactionBegin: startTransaction flag present
 * - TransactionEnd: commitTransaction or abortTransaction command
 */
class MongoDB implements Classifier
{
    /**
     * Read command names (lowercase)
     *
     * @var array<string, true>
     */
    private const READ_COMMANDS = [
        'find' => true,
        'aggregate' => true,
        'count' => true,
        'distinct' => true,
        'listCollections' => true,
        'listDatabases' => true,
        'listIndexes' => true,
        'dbStats' => true,
        'collStats' => true,
        'explain' => true,
        'getMore' => true,
        'serverStatus' => true,
        'buildInfo' => true,
        'connectionStatus' => true,
        'ping' => true,
        'isMaster' => true,
        'ismaster' => true,
        'hello' => true,
    ];

    /**
     * Write command names (lowercase)
     *
     * @var array<string, true>
     */
    private const WRITE_COMMANDS = [
        'insert' => true,
        'update' => true,
        'delete' => true,
        'findAndModify' => true,
        'create' => true,
        'drop' => true,
        'createIndexes' => true,
        'dropIndexes' => true,
        'dropDatabase' => true,
        'renameCollection' => true,
    ];

    /**
     * Commands that may legitimately carry a `startTransaction: true` flag
     * alongside their payload. Only these commands require a BSON scan for
     * the transaction flag — for everything else the command name alone
     * determines the Type, avoiding the linear scan on the hot path.
     *
     * @var array<string, true>
     */
    private const TRANSACTION_ELIGIBLE_COMMANDS = [
        'find' => true,
        'insert' => true,
        'update' => true,
        'delete' => true,
        'aggregate' => true,
        'findAndModify' => true,
    ];

    /**
     * MongoDB OP_MSG opcode
     */
    private const OP_MSG = 2013;

    /**
     * Minimum OP_MSG size: header (16) + flags (4) + section kind (1) + min BSON doc (5)
     */
    private const MIN_MSG_SIZE = 26;

    public function classify(string $data): Type
    {
        $len = \strlen($data);
        if ($len < self::MIN_MSG_SIZE) {
            return Type::Unknown;
        }

        // Verify opcode is OP_MSG (2013)
        $opcode = $this->readUint32($data, 12);
        if ($opcode !== self::OP_MSG) {
            return Type::Unknown;
        }

        // Byte 20: section kind (0 = body)
        if (\ord($data[20]) !== 0) {
            return Type::Unknown;
        }

        // BSON document starts at byte 21
        // BSON doc: 4-byte length, then elements
        // Each element: type byte, cstring name, value
        $bsonOffset = 21;

        // Extract the command name (first BSON key) up front. The command
        // name alone determines the Type for the >99% case; only CRUD
        // commands can piggy-back a `startTransaction: true` flag and
        // therefore warrant the full BSON scan.
        $commandName = $this->extractFirstBsonKey($data, $bsonOffset);

        if ($commandName === null) {
            return Type::Unknown;
        }

        // Transaction end is determined by the command name itself — no scan.
        if ($commandName === 'commitTransaction' || $commandName === 'abortTransaction') {
            return Type::TransactionEnd;
        }

        // Only scan for the startTransaction flag on commands that can
        // legitimately carry it. This avoids a linear BSON walk for pings,
        // hellos, listCollections, serverStatus, etc. on every packet.
        if (isset(self::TRANSACTION_ELIGIBLE_COMMANDS[$commandName])
            && $this->hasBsonKey($data, $bsonOffset, 'startTransaction')
        ) {
            return Type::TransactionBegin;
        }

        // Read commands
        if (isset(self::READ_COMMANDS[$commandName])) {
            return Type::Read;
        }

        // Write commands
        if (isset(self::WRITE_COMMANDS[$commandName])) {
            return Type::Write;
        }

        return Type::Unknown;
    }

    /**
     * Extract the first key name from a BSON document
     *
     * BSON element: type byte (1) + cstring key + value
     * We only need the key name, not the value.
     */
    private function extractFirstBsonKey(string $data, int $bsonOffset): ?string
    {
        $len = \strlen($data);

        if ($bsonOffset + 4 > $len) {
            return null;
        }

        $docLen = $this->readUint32($data, $bsonOffset);

        // Reject negative (32-bit PHP signed overflow) or out-of-bounds lengths.
        // A valid BSON document is at least 5 bytes (length prefix + terminator).
        if ($docLen < 5 || $bsonOffset + $docLen > $len) {
            return null;
        }

        $docEnd = $bsonOffset + $docLen;

        // Skip BSON document length (4 bytes)
        $pos = $bsonOffset + 4;

        if ($pos >= $docEnd) {
            return null;
        }

        // Type byte (0x00 = end of document)
        $type = \ord($data[$pos]);
        if ($type === 0x00) {
            return null;
        }

        $pos++;

        // Read cstring key (null-terminated), bounded by the declared doc length.
        $keyStart = $pos;
        while ($pos < $docEnd && $data[$pos] !== "\x00") {
            $pos++;
        }

        if ($pos >= $docEnd) {
            return null;
        }

        return \substr($data, $keyStart, $pos - $keyStart);
    }

    /**
     * Check if a BSON document contains a specific key
     *
     * Scans through BSON elements looking for the key name.
     * Skips values based on BSON type to advance through elements.
     */
    private function hasBsonKey(string $data, int $bsonOffset, string $targetKey): bool
    {
        $len = \strlen($data);

        if ($bsonOffset + 4 > $len) {
            return false;
        }

        $docLen = $this->readUint32($data, $bsonOffset);

        // Reject negative (32-bit PHP signed overflow) or out-of-bounds lengths.
        // A valid BSON document is at least 5 bytes (length prefix + terminator).
        if ($docLen < 5 || $bsonOffset + $docLen > $len) {
            return false;
        }

        $docEnd = $bsonOffset + $docLen;
        $pos = $bsonOffset + 4;

        while ($pos < $docEnd) {
            $type = \ord($data[$pos]);
            if ($type === 0x00) {
                break;
            }
            $pos++;

            // Read key name (cstring)
            $keyStart = $pos;
            while ($pos < $docEnd && $data[$pos] !== "\x00") {
                $pos++;
            }

            if ($pos >= $docEnd) {
                break;
            }

            $key = \substr($data, $keyStart, $pos - $keyStart);
            $pos++; // skip null terminator

            if ($key === $targetKey) {
                return true;
            }

            // Skip value based on type
            $pos = $this->skipBsonValue($data, $pos, $type, $docEnd);
            if ($pos === false) {
                break;
            }
        }

        return false;
    }

    /**
     * Skip a BSON value to advance past it
     *
     * @return int|false New position after the value, or false on error
     */
    private function skipBsonValue(string $data, int $pos, int $type, int $limit): int|false
    {
        return match ($type) {
            0x01 => $this->advance($pos, 8, $limit),                          // double (8 bytes)
            0x02, 0x0D, 0x0E => $this->skipBsonString($data, $pos, $limit),   // string, JavaScript, Symbol
            0x03, 0x04 => $this->skipBsonDocument($data, $pos, $limit),       // document, array
            0x05 => $this->skipBsonBinary($data, $pos, $limit),               // binary
            0x06, 0x0A, 0xFF, 0x7F => $pos,                                   // undefined, null, min/max key (0 bytes)
            0x07 => $this->advance($pos, 12, $limit),                         // ObjectId (12 bytes)
            0x08 => $this->advance($pos, 1, $limit),                          // boolean (1 byte)
            0x09, 0x11, 0x12 => $this->advance($pos, 8, $limit),              // datetime, timestamp, int64
            0x0B => $this->skipBsonRegex($data, $pos, $limit),                // regex (2 cstrings)
            0x0C => $this->skipBsonDbPointer($data, $pos, $limit),            // DBPointer
            0x10 => $this->advance($pos, 4, $limit),                          // int32 (4 bytes)
            0x13 => $this->advance($pos, 16, $limit),                         // decimal128 (16 bytes)
            default => false,
        };
    }

    /**
     * Advance $pos by a fixed number of bytes, validating against $limit.
     *
     * @return int|false New position, or false if the advance overruns the buffer.
     */
    private function advance(int $pos, int $bytes, int $limit): int|false
    {
        if ($pos + $bytes > $limit) {
            return false;
        }

        return $pos + $bytes;
    }

    private function skipBsonString(string $data, int $pos, int $limit): int|false
    {
        if ($pos + 4 > $limit) {
            return false;
        }
        $strLen = $this->readUint32($data, $pos);

        // On 32-bit PHP `V` yields a signed int; treat negative as invalid.
        // Also reject lengths that would advance past the buffer.
        if ($strLen < 0 || $strLen > ($limit - $pos - 4)) {
            return false;
        }

        return $pos + 4 + $strLen;
    }

    private function skipBsonDocument(string $data, int $pos, int $limit): int|false
    {
        if ($pos + 4 > $limit) {
            return false;
        }
        $docLen = $this->readUint32($data, $pos);

        // On 32-bit PHP `V` yields a signed int; treat negative as invalid.
        // A valid BSON document is at least 5 bytes (length prefix + terminator).
        if ($docLen < 5 || $docLen > $limit - $pos) {
            return false;
        }

        return $pos + $docLen;
    }

    private function skipBsonBinary(string $data, int $pos, int $limit): int|false
    {
        if ($pos + 4 > $limit) {
            return false;
        }
        $binLen = $this->readUint32($data, $pos);

        // On 32-bit PHP `V` yields a signed int; treat negative as invalid.
        // Also reject lengths that would advance past the buffer.
        if ($binLen < 0 || $binLen > ($limit - $pos - 5)) {
            return false;
        }

        return $pos + 4 + 1 + $binLen; // length + subtype byte + data
    }

    private function skipBsonRegex(string $data, int $pos, int $limit): int|false
    {
        // Two cstrings: pattern + options
        while ($pos < $limit && $data[$pos] !== "\x00") {
            $pos++;
        }
        if ($pos >= $limit) {
            return false;
        }
        $pos++; // skip null
        while ($pos < $limit && $data[$pos] !== "\x00") {
            $pos++;
        }
        if ($pos >= $limit) {
            return false;
        }

        return $pos + 1;
    }

    private function skipBsonDbPointer(string $data, int $pos, int $limit): int|false
    {
        // string (4-byte len + data) + 12-byte ObjectId
        $newPos = $this->skipBsonString($data, $pos, $limit);
        if ($newPos === false) {
            return false;
        }

        return $this->advance($newPos, 12, $limit);
    }

    /**
     * Read a little-endian uint32 at $offset. Caller must ensure bounds.
     */
    private function readUint32(string $data, int $offset): int
    {
        /** @var array{1: int}|false $unpacked */
        $unpacked = \unpack('V', $data, $offset);
        if ($unpacked === false) {
            return 0;
        }

        return $unpacked[1];
    }
}
