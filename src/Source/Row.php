<?php

declare(strict_types=1);

namespace Utopia\Schedule\Source;

/**
 * One schedule as the source of truth describes it: a lightweight
 * descriptor the reconciler can diff cheaply. Building the actual
 * {@see Schedule} (parsing the spec, hydrating context) is deferred to
 * the `make` callable and happens only when `version` changes.
 */
final readonly class Row
{
    /**
     * @param string $id stable identity of the schedule in the source
     * @param string $version opaque change marker (an updated-at stamp works); a new
     *                        version re-makes the entry and re-arms a delivered one-shot
     * @param mixed $data raw source data, passed through to `make`
     * @param bool $active false marks a soft-deleted row: the entry is removed in both
     *                     full and incremental syncs
     * @param \DateTimeImmutable|null $activeFrom the moment this definition takes effect —
     *                                            set it to the row's last change time. Occurrences
     *                                            before it are never delivered (no backfill under
     *                                            an old watermark with an old definition), and
     *                                            occurrences after it are delivered even when the
     *                                            watermark passed them before the row was
     *                                            discovered — late, never never. Without it,
     *                                            coverage falls back to the previous sync time
     */
    public function __construct(
        public string $id,
        public string $version,
        public mixed $data = null,
        public bool $active = true,
        public ?\DateTimeImmutable $activeFrom = null,
    ) {}
}
