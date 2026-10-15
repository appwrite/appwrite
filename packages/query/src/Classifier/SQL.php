<?php

namespace Utopia\Query\Classifier;

use Utopia\Query\Classifier;
use Utopia\Query\Type;

/**
 * Abstract SQL Parser
 *
 * Provides shared SQL classification logic used by all wire protocol parsers.
 * Subclasses implement parse() for their specific wire protocol format.
 *
 * Performance: Uses byte-level checks and simple string operations (no regex).
 * Designed to run on every packet with sub-microsecond overhead.
 */
abstract class SQL implements Classifier
{
    /**
     * Read keywords lookup (uppercase)
     *
     * @var array<string, true>
     */
    private const READ_KEYWORDS = [
        'SELECT' => true,
        'SHOW' => true,
        'DESCRIBE' => true,
        'DESC' => true,
        'EXPLAIN' => true,
        'TABLE' => true,
        'VALUES' => true,
    ];

    /**
     * Write keywords lookup (uppercase)
     *
     * @var array<string, true>
     */
    private const WRITE_KEYWORDS = [
        'INSERT' => true,
        'UPDATE' => true,
        'DELETE' => true,
        'CREATE' => true,
        'DROP' => true,
        'ALTER' => true,
        'TRUNCATE' => true,
        'GRANT' => true,
        'REVOKE' => true,
        'LOCK' => true,
        'CALL' => true,
        'DO' => true,
    ];

    /**
     * Transaction-begin keywords (uppercase)
     *
     * @var array<string, true>
     */
    private const TRANSACTION_BEGIN_KEYWORDS = [
        'BEGIN' => true,
        'START' => true,
    ];

    /**
     * Transaction-end keywords (uppercase)
     *
     * @var array<string, true>
     */
    private const TRANSACTION_END_KEYWORDS = [
        'COMMIT' => true,
        'ROLLBACK' => true,
    ];

    /**
     * Other transaction keywords (uppercase)
     *
     * @var array<string, true>
     */
    private const TRANSACTION_KEYWORDS = [
        'SAVEPOINT' => true,
        'RELEASE' => true,
        'SET' => true,
    ];

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
    public function classifySQL(string $query): Type
    {
        $keyword = $this->extractKeyword($query);

        if ($keyword === '') {
            return Type::Unknown;
        }

        // Fast hash-based lookup
        if (isset(self::READ_KEYWORDS[$keyword])) {
            return Type::Read;
        }

        if (isset(self::WRITE_KEYWORDS[$keyword])) {
            return Type::Write;
        }

        if (isset(self::TRANSACTION_BEGIN_KEYWORDS[$keyword])) {
            return Type::TransactionBegin;
        }

        if (isset(self::TRANSACTION_END_KEYWORDS[$keyword])) {
            return Type::TransactionEnd;
        }

        if (isset(self::TRANSACTION_KEYWORDS[$keyword])) {
            return Type::Transaction;
        }

        // COPY requires directional analysis: COPY ... TO = read, COPY ... FROM = write
        if ($keyword === 'COPY') {
            return $this->classifyCopy($query);
        }

        // WITH (CTE): look at the final statement keyword
        if ($keyword === 'WITH') {
            return $this->classifyCTE($query);
        }

        return Type::Unknown;
    }

    /**
     * Extract the first SQL keyword from a query string
     *
     * Skips leading whitespace, SQL comments, and string/identifier literals
     * before the first token. Returns the keyword in uppercase.
     */
    public function extractKeyword(string $query): string
    {
        $len = \strlen($query);
        $pos = $this->skipInsignificant($query, 0, $len);

        if ($pos >= $len) {
            return '';
        }

        // Read keyword until whitespace, '(', ';', or end
        $start = $pos;
        while ($pos < $len) {
            $c = $query[$pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === '(' || $c === ';') {
                break;
            }
            $pos++;
        }

        if ($pos === $start) {
            return '';
        }

        return \strtoupper(\substr($query, $start, $pos - $start));
    }

    /**
     * Advance past whitespace, comments, and quoted literals/identifiers.
     *
     * Returns the new position, which may be $len if the rest of the input
     * was entirely insignificant.
     */
    private function skipInsignificant(string $query, int $pos, int $len): int
    {
        while ($pos < $len) {
            $c = $query[$pos];

            // Whitespace
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r" || $c === "\f") {
                $pos++;

                continue;
            }

            // Line comment: -- ...
            if ($c === '-' && ($pos + 1) < $len && $query[$pos + 1] === '-') {
                $pos = $this->skipLineComment($query, $pos + 2, $len);

                continue;
            }

            // Block comment: /* ... */
            if ($c === '/' && ($pos + 1) < $len && $query[$pos + 1] === '*') {
                $pos = $this->skipBlockComment($query, $pos + 2, $len);

                continue;
            }

            // Single-quoted string literal
            if ($c === "'") {
                $pos = $this->skipSingleQuoted($query, $pos + 1, $len);

                continue;
            }

            // Double-quoted identifier
            if ($c === '"') {
                $pos = $this->skipDoubleQuoted($query, $pos + 1, $len);

                continue;
            }

            // Backtick-quoted identifier (MySQL)
            if ($c === '`') {
                $pos = $this->skipBacktickQuoted($query, $pos + 1, $len);

                continue;
            }

            // Dollar-quoted string ($tag$...$tag$)
            if ($c === '$') {
                $skipped = $this->tryskipDollarQuoted($query, $pos, $len);
                if ($skipped !== null) {
                    $pos = $skipped;

                    continue;
                }
            }

            break;
        }

        return $pos;
    }

    private function skipLineComment(string $query, int $pos, int $len): int
    {
        while ($pos < $len && $query[$pos] !== "\n") {
            $pos++;
        }
        if ($pos < $len) {
            $pos++; // consume the newline
        }

        return $pos;
    }

    private function skipBlockComment(string $query, int $pos, int $len): int
    {
        while ($pos < ($len - 1)) {
            if ($query[$pos] === '*' && $query[$pos + 1] === '/') {
                return $pos + 2;
            }
            $pos++;
        }

        return $len;
    }

    private function skipSingleQuoted(string $query, int $pos, int $len): int
    {
        while ($pos < $len) {
            $c = $query[$pos];
            if ($c === "\\" && ($pos + 1) < $len) {
                $pos += 2;

                continue;
            }
            if ($c === "'") {
                // Doubled-up single quote is an escape for ' inside the literal
                if (($pos + 1) < $len && $query[$pos + 1] === "'") {
                    $pos += 2;

                    continue;
                }

                return $pos + 1;
            }
            $pos++;
        }

        return $len;
    }

    private function skipDoubleQuoted(string $query, int $pos, int $len): int
    {
        while ($pos < $len) {
            $c = $query[$pos];
            if ($c === '"') {
                if (($pos + 1) < $len && $query[$pos + 1] === '"') {
                    $pos += 2;

                    continue;
                }

                return $pos + 1;
            }
            $pos++;
        }

        return $len;
    }

    private function skipBacktickQuoted(string $query, int $pos, int $len): int
    {
        while ($pos < $len) {
            $c = $query[$pos];
            if ($c === '`') {
                if (($pos + 1) < $len && $query[$pos + 1] === '`') {
                    $pos += 2;

                    continue;
                }

                return $pos + 1;
            }
            $pos++;
        }

        return $len;
    }

    /**
     * Try to parse and skip a dollar-quoted PostgreSQL string: $tag$...$tag$.
     *
     * Returns the new position if a valid dollar-quoted block is found,
     * or null if the `$` at $pos does not start a dollar-quoted string.
     */
    private function tryskipDollarQuoted(string $query, int $pos, int $len): ?int
    {
        // Find the closing '$' that ends the opening tag
        $tagStart = $pos + 1;
        $tagEnd = $tagStart;
        while ($tagEnd < $len) {
            $c = $query[$tagEnd];
            if ($c === '$') {
                break;
            }
            // Valid tag: letters, digits, underscore
            if (! (($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || ($c >= '0' && $c <= '9') || $c === '_')) {
                return null;
            }
            $tagEnd++;
        }

        if ($tagEnd >= $len) {
            return null;
        }

        $tag = \substr($query, $pos, $tagEnd - $pos + 1); // includes both $ delimiters
        $tagLen = \strlen($tag);

        $scan = $tagEnd + 1;
        while ($scan < $len) {
            if ($query[$scan] === '$' && ($scan + $tagLen) <= $len
                && \substr($query, $scan, $tagLen) === $tag
            ) {
                return $scan + $tagLen;
            }
            $scan++;
        }

        return $len;
    }

    /**
     * Classify COPY statement direction
     *
     * COPY ... TO stdout/file = READ (export)
     * COPY ... FROM stdin/file = WRITE (import)
     * Default to WRITE for safety
     */
    private function classifyCopy(string $query): Type
    {
        $toPos = \stripos($query, ' TO ');
        $fromPos = \stripos($query, ' FROM ');

        if ($toPos !== false && ($fromPos === false || $toPos < $fromPos)) {
            return Type::Read;
        }

        return Type::Write;
    }

    /**
     * Classify CTE (WITH ... AS (...) SELECT/INSERT/UPDATE/DELETE ...)
     *
     * After the CTE definitions, the first read/write keyword at
     * parenthesis depth 0 is the main statement. The scanner skips over
     * string literals, quoted identifiers, and comments so embedded
     * keywords or parens inside literals/comments cannot fool classification.
     *
     * Default to READ since most CTEs are used with SELECT.
     */
    private function classifyCTE(string $query): Type
    {
        $len = \strlen($query);
        $pos = 0;
        $depth = 0;
        $seenParen = false;

        while ($pos < $len) {
            $skipped = $this->skipLiteralOrComment($query, $pos, $len);
            if ($skipped !== $pos) {
                $pos = $skipped;

                continue;
            }

            $c = $query[$pos];

            if ($c === '(') {
                $depth++;
                $seenParen = true;
                $pos++;

                continue;
            }

            if ($c === ')') {
                $depth--;
                $pos++;

                continue;
            }

            // Only look for keywords at depth 0, after we've seen at least one CTE block
            if ($depth === 0 && $seenParen && (($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z'))) {
                $wordStart = $pos;
                while ($pos < $len) {
                    $ch = $query[$pos];
                    if (($ch >= 'A' && $ch <= 'Z') || ($ch >= 'a' && $ch <= 'z') || ($ch >= '0' && $ch <= '9') || $ch === '_') {
                        $pos++;
                    } else {
                        break;
                    }
                }
                $word = \strtoupper(\substr($query, $wordStart, $pos - $wordStart));

                if (isset(self::READ_KEYWORDS[$word])) {
                    return Type::Read;
                }

                if (isset(self::WRITE_KEYWORDS[$word])) {
                    return Type::Write;
                }

                continue;
            }

            $pos++;
        }

        return Type::Read;
    }

    /**
     * If $pos starts a string literal, quoted identifier, or comment,
     * advance past it and return the new position. Otherwise return $pos
     * unchanged.
     */
    private function skipLiteralOrComment(string $query, int $pos, int $len): int
    {
        if ($pos >= $len) {
            return $pos;
        }

        $c = $query[$pos];

        if ($c === '-' && ($pos + 1) < $len && $query[$pos + 1] === '-') {
            return $this->skipLineComment($query, $pos + 2, $len);
        }

        if ($c === '/' && ($pos + 1) < $len && $query[$pos + 1] === '*') {
            return $this->skipBlockComment($query, $pos + 2, $len);
        }

        if ($c === "'") {
            return $this->skipSingleQuoted($query, $pos + 1, $len);
        }

        if ($c === '"') {
            return $this->skipDoubleQuoted($query, $pos + 1, $len);
        }

        if ($c === '`') {
            return $this->skipBacktickQuoted($query, $pos + 1, $len);
        }

        if ($c === '$') {
            $skipped = $this->tryskipDollarQuoted($query, $pos, $len);
            if ($skipped !== null) {
                return $skipped;
            }
        }

        return $pos;
    }
}
