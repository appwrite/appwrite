<?php

namespace Appwrite\Database;

class Status
{
    public const QUEUED = 'queued';
    public const FAILED = 'failed';
    public const DELETING_FAILED = 'failed';
    public const AVAILABLE = 'available';
    public const DELETING = 'deleting';
    public const CREATING = 'creating';

    public static function getV18status(string $status): string
    {
        return match ($status) {
            self::CREATING => 'processing',
            self::DELETING_FAILED => 'stuck',
            default => $status
        };
    }
}
