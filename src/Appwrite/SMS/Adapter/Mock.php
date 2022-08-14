<?php

namespace Appwrite\SMS\Adapter;

use Appwrite\SMS\Adapter;

class Mock extends Adapter
{
    /**
     * @var string
     */
    public static string $digits = '123456';

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
}
