<?php

namespace Utopia\Mqtt\Packet;

class Unsuback
{
    public const SUCCESS = 0x00;
    public const NO_SUBSCRIPTION_EXISTED = 0x11;
    public const NOT_AUTHORIZED = 0x87;

    /** @var list<int> */
    private array $codes = [];

    public function success(): self
    {
        $this->codes[] = self::SUCCESS;

        return $this;
    }

    public function fail(int $reasonCode = self::NOT_AUTHORIZED): self
    {
        $this->codes[] = $reasonCode;

        return $this;
    }

    /** @return list<int> */
    public function codes(): array
    {
        return $this->codes;
    }
}
