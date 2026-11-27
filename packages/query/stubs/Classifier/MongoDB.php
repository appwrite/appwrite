<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Classifier;

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
class MongoDB implements \Utopia\Query\Classifier
{
    public function classify(string $data): \Utopia\Query\Type
    {
    }
}
