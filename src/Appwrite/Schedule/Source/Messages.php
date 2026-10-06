<?php

namespace Appwrite\Schedule\Source;

use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\At;

final class Messages extends Database
{
    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_MESSAGE;
    }

    #[\Override]
    protected function collection(): string
    {
        return RESOURCE_TYPE_MESSAGES;
    }

    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        return new At(new \DateTimeImmutable((string) $schedule['schedule']));
    }
}
