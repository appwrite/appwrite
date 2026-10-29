<?php

namespace Appwrite\Alert;

/**
 * Copy that replaces what an alert email works out from its rule, for alerts
 * people know by other words: a budget cap reads better as "You've reached
 * the budget cap" than as a metric at or above a threshold. Each part left
 * null keeps the generated one.
 */
readonly class Wording
{
    public function __construct(
        public ?string $subject = null,
        public ?string $heading = null,
        public ?string $summary = null,
        public ?string $action = null,
    ) {
    }
}
