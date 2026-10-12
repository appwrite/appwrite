<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\Email\SMTP;

use Utopia\SMTP\Exception\ConnectionException;

/**
 * Every host in the list failed before a session was open.
 *
 * Still a connection failure to anything that catches one, and it keeps the
 * per-host message. What it adds is whether trying the list again can go any
 * differently: only when every host refused for good, such as a 535 for the
 * credentials or a 554 in place of the greeting. One host that timed out or
 * answered 4xx may well answer next time.
 */
class NoHostAnswered extends ConnectionException
{
    public function __construct(string $message, public readonly bool $permanent)
    {
        parent::__construct($message);
    }
}
