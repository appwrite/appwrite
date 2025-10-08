<?php

namespace Appwrite\Auth\Subscription\Exception;

use Exception;

class SubscriptionException extends Exception
{
    public function __construct(string $message = '', int $code = 0, Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
