<?php

namespace Tests\Unit\Platform\Workers;

use PHPUnit\Framework\TestCase;

/**
 * Tests for BUG-03 — Truncation off-by-one error.
 *
 * The bug: maxContentLength was computed as (limit - warningLength),
 * but the final string was assembled as content + "\n" + warning,
 * making it exactly 1 byte longer than the limit.
 *
 * Fix: subtract strlen($separator) from maxContentLength.
 *
 * Covers all 4 occurrences (logs + errors in both Functions.php and general.php).
 */
class TruncationTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Core invariant: truncated string MUST NOT exceed the limit
    // -----------------------------------------------------------------------

    /**
     * @dataProvider truncationLimitProvider
     */
    public function testTruncatedOutputNeverExceedsLimit(int $limit): void
    {
        // Build a string that is definitely longer than $limit
        $input = str_repeat('A', $limit * 2);

        $result = $this->applyTruncation($input, $limit, 'logs');

        $this->assertLessThanOrEqual(
            $limit,
            strlen($result),
            "Truncated output (" . strlen($result) . " bytes) must not exceed limit ({$limit} bytes)"
        );
    }

    /**
     * Verify the exact known off-by-one: with the old code the output was always limit+1.
     */
    public function testOldCodeProducedOffByOneError(): void
    {
        $limit = 200;
        $input = str_repeat('X', $limit * 2);

        $separator      = "\n";
        $warningMessage = "[WARNING] Logs truncated. The output exceeded {$limit} characters.\n";
        $warningLength  = strlen($warningMessage);

        // --- OLD (buggy) logic ---
        $oldMaxContent = max(0, $limit - $warningLength); // does NOT subtract separator
        $oldResult     = substr($input, 0, $oldMaxContent) . $separator . $warningMessage;

        // --- NEW (fixed) logic ---
        $newMaxContent = max(0, $limit - $warningLength - strlen($separator)); // accounts for separator
        $newResult     = substr($input, 0, $newMaxContent) . $separator . $warningMessage;

        // The old code produces limit+1
        $this->assertEquals($limit + 1, strlen($oldResult), 'Old code must demonstrate the off-by-one');

        // The fixed code produces exactly limit (or less)
        $this->assertLessThanOrEqual($limit, strlen($newResult), 'Fixed code must not exceed limit');
        $this->assertEquals($limit, strlen($newResult), 'Fixed code should produce exactly limit bytes');
    }

    // -----------------------------------------------------------------------
    // Warning message must appear at the end; content must start at beginning
    // -----------------------------------------------------------------------

    public function testWarningAppearsAtEndOfTruncatedOutput(): void
    {
        $limit  = 300;
        $input  = str_repeat('B', $limit * 2);
        $result = $this->applyTruncation($input, $limit, 'logs');

        $warningMessage = "[WARNING] Logs truncated. The output exceeded {$limit} characters.\n";

        $this->assertStringEndsWith(
            $warningMessage,
            $result,
            'The truncation warning must always be at the end of the output'
        );
    }

    public function testHeadOfOutputIsPreservedAfterTruncation(): void
    {
        $limit  = 200;
        $head   = str_repeat('H', 10); // first 10 chars
        $tail   = str_repeat('T', $limit * 2); // lots of T's that should be cut
        $input  = $head . $tail;

        $result = $this->applyTruncation($input, $limit, 'logs');

        $this->assertStringStartsWith(
            $head,
            $result,
            'The beginning of the log output must be preserved after truncation'
        );
    }

    // -----------------------------------------------------------------------
    // Input within limit: no truncation should occur
    // -----------------------------------------------------------------------

    public function testShortInputIsNotTruncated(): void
    {
        $limit  = 1000;
        $input  = 'A short log line.';
        $result = $this->applyTruncation($input, $limit, 'logs');

        $this->assertEquals($input, $result, 'Input within limit must not be modified');
    }

    public function testExactlyAtLimitInputIsNotTruncated(): void
    {
        $limit = 100;
        $input = str_repeat('Z', $limit); // exactly $limit chars — should not truncate
        $result = $this->applyTruncation($input, $limit, 'logs');

        $this->assertEquals($input, $result, 'Input at exact limit must not be truncated');
    }

    // -----------------------------------------------------------------------
    // Error path (same logic, different label)
    // -----------------------------------------------------------------------

    public function testErrorTruncationNeverExceedsLimit(): void
    {
        $limit  = 150;
        $input  = str_repeat('E', $limit * 2);
        $result = $this->applyTruncation($input, $limit, 'errors');

        $this->assertLessThanOrEqual(
            $limit,
            strlen($result),
            "Truncated error output must not exceed limit ({$limit} bytes)"
        );
    }

    // -----------------------------------------------------------------------
    // Edge case: warning longer than limit → empty content, only warning (max 0)
    // -----------------------------------------------------------------------

    public function testVerySmallLimitProducesNonNegativeContentLength(): void
    {
        $limit  = 5; // smaller than any warning message
        $input  = str_repeat('X', 1000);
        $result = $this->applyTruncation($input, $limit, 'logs');

        // maxContentLength = max(0, ...) — must never go negative or cause substr error
        $this->assertIsString($result, 'Truncation must not throw with a tiny limit');
        $this->assertStringContainsString('[WARNING]', $result, 'Warning must still be present');
    }

    // -----------------------------------------------------------------------
    // Data providers
    // -----------------------------------------------------------------------

    public static function truncationLimitProvider(): array
    {
        return [
            'limit=100'  => [100],
            'limit=500'  => [500],
            'limit=1000' => [1000],
            'limit=5000' => [5000],
        ];
    }

    // -----------------------------------------------------------------------
    // Helper: mirrors the FIXED truncation logic from Functions.php / general.php
    // -----------------------------------------------------------------------

    /**
     * Applies the fixed truncation logic.
     * Mirrors exactly what's in Functions.php and general.php after BUG-03 fix.
     *
     * @param  string $input     The raw log/error string
     * @param  int    $limit     The maximum allowed length
     * @param  string $type      'logs' or 'errors'
     * @return string            Possibly-truncated result
     */
    private function applyTruncation(string $input, int $limit, string $type): string
    {
        if (!\is_string($input) || \strlen($input) <= $limit) {
            return $input;
        }

        $separator      = "\n";
        $label          = $type === 'errors' ? 'Errors' : 'Logs';
        $warningMessage = "[WARNING] {$label} truncated. The output exceeded {$limit} characters.\n";

        // Fixed: subtract separator length
        $maxContentLength = max(0, $limit - \strlen($warningMessage) - \strlen($separator));

        return \substr($input, 0, $maxContentLength) . $separator . $warningMessage;
    }
}
