<?php

declare(strict_types=1);

namespace Utopia\DNS\Exception\Zone;

/**
 * Exception thrown during Zone file import.
 */
final class ImportException extends \RuntimeException
{
    public function __construct(
        private readonly string $content,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Returns the content of the Zone file.
     */
    public function getContent(): string
    {
        return $this->content;
    }
}
