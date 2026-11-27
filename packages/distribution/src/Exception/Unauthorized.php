<?php

declare(strict_types=1);

namespace Utopia\Distribution\Exception;

use Utopia\Distribution\Exception;
use Utopia\Distribution\Issue;

final class Unauthorized extends Exception
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        string $message = '',
        array $issues = [],
        ?\Throwable $previous = null,
        private readonly bool $credential = false,
        ?string $body = null,
    ) {
        parent::__construct($message, $issues, $previous, $body);
    }

    public function isCredential(): bool
    {
        return $this->credential;
    }
}
