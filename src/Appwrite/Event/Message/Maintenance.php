<?php

namespace Appwrite\Event\Message;

/**
 * One due moment of the inactive project cycle: the slot it owns, out of how many, and how long the cycle is.
 */
final class Maintenance extends Base
{
    public function __construct(
        public readonly int $slot,
        public readonly int $slots,
        public readonly int $due,
        public readonly int $period,
    ) {
    }

    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'slots' => $this->slots,
            'due' => $this->due,
            'period' => $this->period,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            slot: (int) ($data['slot'] ?? 0),
            slots: (int) ($data['slots'] ?? 1),
            due: (int) ($data['due'] ?? 0),
            period: (int) ($data['period'] ?? 0),
        );
    }
}
