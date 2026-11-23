<?php

namespace Utopia\Abuse\Challenges\ProofOfWork\Nonce;

/**
 * Outcome of attempting to consume a proof-of-work nonce. Tri-state on purpose:
 * "already consumed" and "backing store unreachable" are different failures and
 * must be handled differently by {@see \Utopia\Abuse\Challenges\ProofOfWork::verify()}.
 *
 * A replay ({@see Outcome::Consumed}) is a real attack signal and is
 * rejected. A store outage ({@see Outcome::Unavailable}) is infra noise:
 * treating it as a replay would loop a legitimate solver forever (403 →
 * re-challenge → solve → store still down → 403 …), so the caller fails *open
 * on the nonce* — the solution is still validated and the token's short expiry
 * bounds the degraded window.
 */
enum Outcome
{
    /** First claim of this nonce — the solve may proceed. */
    case Fresh;

    /** Nonce already claimed — the token/solution is being replayed. */
    case Consumed;

    /** Backing store could not be reached — the replay guard did not run. */
    case Unavailable;
}
