<?php

declare(strict_types=1);

namespace Utopia\NATS\KeyValue;

/**
 * Options controlling what a {@see KeyValue::watch()} subscription delivers.
 */
final class KeyValueWatchOptions
{
    /**
     * @param bool $includeHistory Deliver every stored revision first (deliver_policy=all), then live updates.
     * @param bool $updatesOnly    Deliver only new updates from now on (deliver_policy=new). This is the default
     *                             when no options are supplied.
     * @param bool $ignoreDeletes  Do not invoke the callback for DEL/PURGE marker entries.
     * @param bool $metaOnly       Deliver headers only, without the value body (headers_only on the consumer).
     */
    public function __construct(
        public readonly bool $includeHistory = false,
        public readonly bool $updatesOnly = false,
        public readonly bool $ignoreDeletes = false,
        public readonly bool $metaOnly = false,
    ) {}
}
