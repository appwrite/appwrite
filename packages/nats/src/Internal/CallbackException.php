<?php

declare(strict_types=1);

namespace Utopia\NATS\Internal;

/** @internal Distinguishes application exceptions from protocol/transport failures. */
final class CallbackException extends \RuntimeException
{
    public function __construct(public readonly \Throwable $cause)
    {
        parent::__construct($cause->getMessage(), previous: $cause);
    }
}
