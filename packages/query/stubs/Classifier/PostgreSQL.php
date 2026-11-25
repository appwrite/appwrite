<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Classifier;

use Utopia\Query\Type;

/**
 * PostgreSQL Wire Protocol Parser
 *
 * Parses PostgreSQL frontend (client → server) messages and classifies queries.
 *
 * Wire protocol message format:
 * - Byte 0: Message type character
 * - Bytes 1-4: Length (big-endian int32, includes self but not type byte)
 * - Bytes 5+: Message body
 *
 * Supported message types:
 * - 'Q' (Query): Simple query — body is null-terminated SQL string
 * - 'P' (Parse): Extended query — prepared statement, routed to primary
 * - 'B' (Bind): Extended query — parameter binding, routed to primary
 * - 'E' (Execute): Extended query — execute prepared, routed to primary
 */
class PostgreSQL extends \Utopia\Query\Classifier\SQL
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
