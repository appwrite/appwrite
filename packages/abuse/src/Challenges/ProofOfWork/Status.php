<?php

namespace Utopia\Abuse\Challenges\ProofOfWork;

/**
 * Outcome of verifying a proof-of-work submission. Distinct cases so the caller
 * can log which check rejected the attempt.
 */
enum Status: string
{
    /** Signature, expiry, binding, nonce and solution all passed. */
    case Valid = 'valid';

    /** Bad signature, expired, issued for another purpose, or malformed. */
    case InvalidToken = 'invalid_token';

    /** The token is bound to a different client. */
    case BindingMismatch = 'binding_mismatch';

    /** The nonce was already used: the submission is a replay. */
    case Replay = 'replay';

    /** The nonce store could not be reached and the challenge requires it. */
    case NonceUnavailable = 'nonce_unavailable';

    /** The nonce was fresh, but the solution does not meet the difficulty. */
    case InvalidSolution = 'invalid_solution';
}
