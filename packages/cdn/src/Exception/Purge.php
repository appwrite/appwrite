<?php

namespace Utopia\Cdn\Exception;

/**
 * Raised when a purge that was attempted on several providers failed on at
 * least one of them. Carries every underlying failure, so a caller can log per
 * provider instead of seeing only whichever provider happened to fail first.
 */
class Purge extends \RuntimeException
{
    /**
     * @param array<int, \Throwable> $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message, 0, $errors[0] ?? null);
    }

    /**
     * @return array<int, \Throwable>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
