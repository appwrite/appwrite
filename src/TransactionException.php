<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * The envelope or the message data was refused. The reply says whether retrying
 * is worth it.
 */
class TransactionException extends Exception
{
    public function __construct(public readonly Reply $reply, string $message = '')
    {
        parent::__construct($message === '' ? (string) $reply : $message, $reply->code);
    }

    public function isTransient(): bool
    {
        return $this->reply->outcome === Outcome::Transient;
    }

    public function isPermanent(): bool
    {
        return $this->reply->outcome === Outcome::Permanent;
    }
}
