<?php

namespace Appwrite\Auth\Phone;

use Appwrite\Auth\Phone;

class Mock extends Phone
{
    /**
     * @var string
     */
    public static string $defaultDigits = '123456';

    /**
     * @param string $from
     * @param string $to
     * @param string $message
     * @return void
     */
    public function send(string $from, string $to, string $message): void
    {
        return;
    }

    /**
     * @param int $digits
     * @return string
     */
    public function generateSecretDigits(int $digits = 6): string
    {
        return self::$defaultDigits;
    }
}
