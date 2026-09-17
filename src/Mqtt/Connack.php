<?php

namespace Utopia\Mqtt;

class Connack
{
    public const SUCCESS = 0x00;
    public const UNSPECIFIED_ERROR = 0x80;
    public const BAD_CREDENTIALS = 0x86;
    public const NOT_AUTHORIZED = 0x87;
    public const SERVER_UNAVAILABLE = 0x88;
    public const SERVER_BUSY = 0x89;
    public const QUOTA_EXCEEDED = 0x97;

    private function __construct(
        public readonly int $reasonCode,
        public readonly bool $sessionPresent = false,
        public readonly ?Properties $properties = null,
    ) {
    }

    public static function accept(bool $sessionPresent = false, ?Properties $properties = null): self
    {
        return new self(self::SUCCESS, $sessionPresent, $properties);
    }

    public static function refuse(int $reasonCode = self::NOT_AUTHORIZED, ?Properties $properties = null): self
    {
        return new self($reasonCode, false, $properties);
    }

    public function accepted(): bool
    {
        return $this->reasonCode === self::SUCCESS;
    }
}
