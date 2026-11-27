<?php

namespace Appwrite\Distribution\Build;

enum Status: string
{
    case Waiting = 'waiting';
    case Processing = 'processing';
    case Building = 'building';
    case Signing = 'signing';
    case Ready = 'ready';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function canTransition(self $to): bool
    {
        return \in_array($to, $this->next(), true);
    }

    /**
     * @return list<self>
     */
    private function next(): array
    {
        return match ($this) {
            self::Waiting => [self::Processing, self::Building, self::Signing, self::Ready, self::Failed, self::Canceled],
            self::Processing => [self::Waiting, self::Building, self::Signing, self::Ready, self::Failed, self::Canceled],
            self::Building => [self::Waiting, self::Signing, self::Ready, self::Failed, self::Canceled],
            self::Signing => [self::Waiting, self::Ready, self::Failed, self::Canceled],
            self::Ready, self::Failed, self::Canceled => [],
        };
    }
}
