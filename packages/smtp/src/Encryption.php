<?php

declare(strict_types=1);

namespace Utopia\SMTP;

enum Encryption
{
    /** Plaintext, and never upgraded. */
    case None;

    /** STARTTLS when the server advertises it, plaintext when it does not. */
    case Opportunistic;

    /** STARTTLS, and a failure when the server does not offer it. */
    case StartTls;

    /** TLS from the first byte, as RFC 8314 asks for on port 465. */
    case Implicit;
}
