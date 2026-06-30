<?php

namespace Executor;

class Exception extends \Exception
{
    public const string GENERAL_UNKNOWN = 'general_unknown';
    public const string BUILD_FAILED = 'build_failed';
    public const string RUNTIME_FAILED = 'runtime_failed';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly string $type = self::GENERAL_UNKNOWN,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getType(): string
    {
        return $this->type;
    }
}
