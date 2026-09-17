<?php

namespace Utopia\Mqtt;

class Suback
{
    public const DENIED = 0x80;

    /** @var list<int> */
    private array $codes = [];

    public function grant(int $qos): self
    {
        $this->codes[] = $qos;

        return $this;
    }

    public function deny(int $reasonCode = self::DENIED): self
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
