<?php

namespace Appwrite\Distribution\Submission;

enum Step: string
{
    case Validated = 'validated';
    case Located = 'located';
    case Uploaded = 'uploaded';
    case Processed = 'processed';
    case Released = 'released';
    case Settled = 'settled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return \array_map(static fn (self $step): string => $step->value, self::cases());
    }
}
