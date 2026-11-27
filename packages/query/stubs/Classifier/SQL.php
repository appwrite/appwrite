<?php

// Registered by the utopia PHP extension (crates/extension in appwrite/appwrite). This file only
// declares the class for static analysis and editors; it is never loaded.

namespace Utopia\Query\Classifier;

/**
 * Abstract SQL Parser
 *
 * Provides shared SQL classification logic used by all wire protocol parsers.
 * Subclasses implement parse() for their specific wire protocol format.
 *
 * Performance: Uses byte-level checks and simple string operations (no regex).
 * Designed to run on every packet with sub-microsecond overhead.
 */
abstract class SQL implements \Utopia\Query\Classifier
{
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
