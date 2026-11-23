<?php

declare(strict_types=1);

namespace Utopia\Distribution;

abstract class Exception extends \RuntimeException
{
    /**
     * @param list<Issue> $issues
     */
    public function __construct(
        string $message = '',
        private readonly array $issues = [],
        ?\Throwable $previous = null,
        private readonly ?string $body = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return list<Issue>
     */
    public function getIssues(): array
    {
        return $this->issues;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }
}
