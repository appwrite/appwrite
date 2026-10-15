<?php

declare(strict_types=1);

namespace Utopia\NATS;

final class Inbox
{
    private const CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const ID_LENGTH = 22;

    public static function create(string $prefix = '_INBOX'): string
    {
        return $prefix . '.' . self::generateId();
    }

    public static function generateId(): string
    {
        $id = '';
        $bytes = random_bytes(self::ID_LENGTH);
        $charsetLen = \strlen(self::CHARSET);

        for ($i = 0; $i < self::ID_LENGTH; $i++) {
            $id .= self::CHARSET[\ord($bytes[$i]) % $charsetLen];
        }

        return $id;
    }
}
