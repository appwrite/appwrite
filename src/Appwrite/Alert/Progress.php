<?php

namespace Appwrite\Alert;

/**
 * A bar under an alert email's card showing how far along a limit the value
 * is, with its two ends labelled and a note between them.
 */
readonly class Progress
{
    public function __construct(
        public float $percent,
        public string $start,
        public string $end,
        public string $note = '',
    ) {
    }
}
