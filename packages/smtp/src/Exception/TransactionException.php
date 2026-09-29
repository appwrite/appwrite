<?php

declare(strict_types=1);

namespace Utopia\SMTP\Exception;

use Utopia\SMTP\Outcome;
use Utopia\SMTP\Reply;

/**
 * The envelope or the message data was refused.
 *
 * The server answered, which is why the session survives this. The reply says
 * whether answering the same way twice is likely.
 */
class TransactionException extends SmtpException
{
    public function __construct(public readonly Reply $reply, string $message = '')
    {
        parent::__construct($message === '' ? (string) $reply : $message, $reply->code);
    }

    /**
     * Whether putting this message back on the queue is worth anything.
     */
    public function isTransient(): bool
    {
        return $this->reply->outcome === Outcome::Transient;
    }

    public function isPermanent(): bool
    {
        return $this->reply->outcome === Outcome::Permanent;
    }
}
