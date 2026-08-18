<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Schedule\Source\Row;

/**
 * A {@see Source} that can also report what changed, letting the
 * scheduler sync often without re-reading everything.
 *
 * Implement it alongside `Source` when the underlying store can answer
 * "what moved since?" cheaply — typically an indexed updated-at column:
 *
 * ```php
 * final class FunctionSchedules implements Source, Changes { ... }
 * ```
 *
 * It is strictly an optimization. A change feed cannot see a hard
 * delete, so the scheduler still takes a full snapshot every `relist`
 * seconds to converge removals; between those, it asks only for
 * changes. Sources that cannot answer cheaply simply do not implement
 * this, and every sync is a full snapshot.
 */
interface Changes
{
    /**
     * Rows whose definition changed at or after $moment, including ones
     * that were disabled — return those as `active: false` so the
     * scheduler drops them without waiting for the next snapshot.
     *
     * Overlapping answers are harmless: the scheduler diffs by version,
     * so re-reporting an unchanged row costs a string comparison.
     *
     * @return iterable<Row>
     */
    public function since(\DateTimeImmutable $moment): iterable;
}
