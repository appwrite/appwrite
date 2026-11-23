<?php

declare(strict_types=1);

namespace Utopia\Distribution;

use Utopia\Distribution\Exception\Invalid;

final readonly class State
{
    public const string STATUS_PROCESSING = 'processing';

    public const string STATUS_PENDING = 'pending';

    public const string STATUS_REVIEWING = 'reviewing';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_REJECTED = 'rejected';

    public const string STATUS_HALTED = 'halted';

    private const array STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_PENDING,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_HALTED,
    ];

    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        public string $status,
        public string $raw,
        public float $fraction = 1.0,
        public array $issues = [],
    ) {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new Invalid("State status \"{$status}\" is not supported");
        }

        if (is_nan($fraction) || $fraction <= 0.0 || $fraction > 1.0) {
            throw new Invalid('State fraction must be greater than 0 and at most 1');
        }
    }
}
