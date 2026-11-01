<?php

namespace Appwrite\Alert;

/**
 * Copy and parts that replace what an alert email works out from its rule, for
 * alerts people know by other words: a budget cap reads better as "You've
 * reached the $40.00 budget cap" with the spend against the cap than as a
 * metric at or above a threshold. Each part left null keeps the generated one.
 */
readonly class Wording
{
    /**
     * @param ?string $tone `warning` shows the status in amber instead of red or green
     */
    public function __construct(
        public ?string $subject = null,
        public ?string $heading = null,
        public ?string $summary = null,
        public ?string $status = null,
        public ?string $tone = null,
        public ?Figure $value = null,
        public ?Figure $threshold = null,
        public ?Progress $progress = null,
        public ?string $callout = null,
        public ?string $action = null,
        public ?string $link = null,
        public ?string $linkUrl = null,
    ) {
    }
}
