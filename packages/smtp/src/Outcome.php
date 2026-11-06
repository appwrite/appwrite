<?php

declare(strict_types=1);

namespace Utopia\SMTP;

use Utopia\SMTP\Exception\ProtocolException;

/**
 * The four reply classes of RFC 5321 section 4.2.1. One value rather than a set
 * of predicates: a reply is exactly one of these, and 3yz is not a failure.
 */
enum Outcome
{
    /** 2yz — the command worked. */
    case Success;

    /** 3yz — the command was understood and the server is waiting for more, like the 354 before message data. */
    case Intermediate;

    /** 4yz — it failed, but the same command may work later. */
    case Transient;

    /** 5yz — it failed, and sending it again changes nothing. */
    case Permanent;

    public static function fromCode(int $code): self
    {
        return match (intdiv($code, 100)) {
            2 => self::Success,
            3 => self::Intermediate,
            4 => self::Transient,
            5 => self::Permanent,
            default => throw new ProtocolException("Not an SMTP reply code: {$code}"),
        };
    }
}
