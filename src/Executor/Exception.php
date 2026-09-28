<?php

namespace Executor;

class Exception extends \Exception
{
    public const string GENERAL_UNKNOWN = 'general_unknown';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly string $type = self::GENERAL_UNKNOWN,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Build the failure the executor answered with an error status. A body the
     * executor did not shape (a gateway's plain-text page) carries no type.
     */
    public static function fromResponse(int $status, mixed $body): self
    {
        $message = \is_string($body) ? $body : ($body['message'] ?? '');
        $type = \is_array($body) ? ($body['type'] ?? self::GENERAL_UNKNOWN) : self::GENERAL_UNKNOWN;

        return new self($message, $status, type: $type);
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Whether the executor named the failure itself, such as a runtime that
     * crashed or timed out. It reports those on its own side, and for a function
     * they are the execution's result. An unclassified failure is one it could
     * not explain, or one where no executor answered at all.
     */
    public function isClassified(): bool
    {
        return $this->type !== self::GENERAL_UNKNOWN;
    }
}
