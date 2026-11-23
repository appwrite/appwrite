<?php

namespace Utopia\Abuse\Challenges\ProofOfWork;

/**
 * Outcome of verifying a proof-of-work submission. Distinct cases so the caller
 * can log which control rejected the attempt (all non-Valid cases map to a
 * re-challenge, never a hard block).
 */
enum Status: string
{
    /** Signature + expiry + binding + nonce + solution all passed. */
    case Valid = 'valid';

    /** Bad signature or expired token. */
    case InvalidToken = 'invalid_token';

    /** Token bound to a different (project, IP prefix, UA). */
    case BindingMismatch = 'binding_mismatch';

    /** Nonce already consumed — the token/solution is being replayed. */
    case Replay = 'replay';

    /** Nonce was fresh, but the solution does not meet the difficulty. */
    case InvalidSolution = 'invalid_solution';
}
