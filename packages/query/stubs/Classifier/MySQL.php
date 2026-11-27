<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Classifier;

use Utopia\Query\Type;

/**
 * MySQL Wire Protocol Parser
 *
 * Parses MySQL client protocol packets and classifies queries.
 *
 * Packet format:
 * - Bytes 0-2: Payload length (little-endian 3-byte int)
 * - Byte 3: Sequence ID
 * - Byte 4: Command type
 * - Bytes 5+: Command payload
 *
 * Supported commands:
 * - COM_QUERY (0x03): Text query — followed by query string
 * - COM_STMT_PREPARE (0x16): Prepared statement — routed to primary
 * - COM_STMT_EXECUTE (0x17): Execute prepared — routed to primary
 * - COM_STMT_SEND_LONG_DATA (0x18): Long data — routed to primary
 * - COM_STMT_CLOSE (0x19): Close statement — routed to primary
 * - COM_STMT_RESET (0x1A): Reset statement — routed to primary
 */
class MySQL extends \Utopia\Query\Classifier\SQL
{
    public function classify(string $data): \Utopia\Query\Type
    {
    }
    /**
     * Classify a SQL query string by its leading keyword
     *
     * Handles:
     * - Leading whitespace (spaces, tabs, newlines)
     * - SQL comments: line comments (--) and block comments
     * - Mixed case keywords
     * - COPY ... TO (read) vs COPY ... FROM (write)
     * - CTE: WITH ... SELECT (read) vs WITH ... INSERT/UPDATE/DELETE (write)
     */
    public function classifySQL(string $query): \Utopia\Query\Type
    {
    }
    /**
     * Extract the first SQL keyword from a query string
     *
     * Skips leading whitespace, SQL comments, and string/identifier literals
     * before the first token. Returns the keyword in uppercase.
     */
    public function extractKeyword(string $query): string
    {
    }
}
