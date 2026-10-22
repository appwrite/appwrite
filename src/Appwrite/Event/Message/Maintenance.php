<?php

namespace Appwrite\Event\Message;

use Utopia\Queue\PermanentFailure;

/**
 * One due moment of the inactive project cycle: the slot it owns, out of how many, how long the cycle is, and the
 * region whose projects it covers.
 *
 * A sweep that defers behind the deletes backlog sends the message again with where to resume: $pass is the inactive
 * condition it was reading, and $cursor the last project it read through, '' for the start of the pass: the last $sequence
 * of the block before when sequences are integers, otherwise the project's ID.
 */
final class Maintenance extends Base
{
    public function __construct(
        public readonly int $slot,
        public readonly int $slots,
        public readonly int $due,
        public readonly int $period,
        public readonly string $region,
        public readonly int $pass = 0,
        public readonly string $cursor = '',
    ) {
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            'slot' => $this->slot,
            'slots' => $this->slots,
            'due' => $this->due,
            'period' => $this->period,
            'region' => $this->region,
            'pass' => $this->pass,
            'cursor' => $this->cursor,
        ];
    }

    /**
     * @throws PermanentFailure when the payload names no slot count, period or region, which no retry can supply
     */
    #[\Override]
    public static function fromArray(array $data): static
    {
        $slots = (int) ($data['slots'] ?? 0);
        $period = (int) ($data['period'] ?? 0);
        $region = (string) ($data['region'] ?? '');

        if ($slots < 1 || $period < 1 || $region === '') {
            throw new PermanentFailure('Maintenance message needs positive slots and period and a region');
        }

        return new self(
            slot: (int) ($data['slot'] ?? 0),
            slots: $slots,
            due: (int) ($data['due'] ?? 0),
            period: $period,
            region: $region,
            pass: (int) ($data['pass'] ?? 0),
            cursor: (string) ($data['cursor'] ?? ''),
        );
    }
}
