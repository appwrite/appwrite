<?php

declare(strict_types=1);

namespace Utopia\SMTP;

/**
 * How much of the certificate to believe.
 *
 * Issuer and hostname are separate questions, and answering "no" to the first
 * is not a reason to stop asking the second.
 */
enum Verification
{
    /** A chain the system trusts, presented by the host we dialled. */
    case Full;

    /** Any issuer, but still the host we dialled. For a private authority or a test rig. */
    case SelfSigned;

    /** Nothing is checked. Encryption without an answer to who is on the other end. */
    case None;
}
