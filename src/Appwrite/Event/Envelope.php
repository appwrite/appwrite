<?php

namespace Appwrite\Event;

final class Envelope
{
    public static function forOutcome(string $operationId, string $outcome): string
    {
        return \hash('sha256', $operationId . "\0" . $outcome);
    }
}
