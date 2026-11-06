<?php

declare(strict_types=1);

namespace Utopia\Schedule;

use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;

/**
 * Where a scheduler's schedules come from, usually a database.
 *
 * The scheduler converges memory to whatever {@see Source::snapshot()}
 * reports, so additions, updates and removals — including hard deletes
 * no change feed can see — all follow from stating the desired set. This
 * is the correctness mechanism; a change feed ({@see Changes}) is only
 * ever an optimization on top of it.
 */
interface Source
{
    /**
     * Every schedule that should be running, as cheap {@see Row}
     * descriptors, at the moment of the call.
     *
     * Returning a generator is encouraged: rows are consumed once, so a
     * paged query never has to be materialized. Throwing part-way
     * through discards the whole batch rather than reading as a mass
     * removal, so a failing query is safe.
     *
     * @return iterable<Row>
     */
    public function snapshot(): iterable;

    /**
     * Build the schedule a row describes: parse its expression, hydrate
     * whatever context the handler will need, and return both as an
     * {@see Entry}.
     *
     * Called only when a row is new or its version changed, so this is
     * where expensive work belongs. Throwing skips that one row — the
     * failure is reported and the row's previous entry, if any, stays.
     */
    public function make(Row $row): Entry;
}
