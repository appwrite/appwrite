<?php

namespace Utopia\Abuse\Challenge;

/**
 * Outcome of verifying an answer to any challenge. Distinct cases so the caller
 * can log which check rejected the attempt.
 */
enum Status: string
{
    /** Token, binding and answer all passed. */
    case Valid = 'valid';

    /** Bad signature, expired, issued for another purpose, or malformed. */
    case InvalidToken = 'invalid_token';

    /** The token is bound to different params, e.g. another client. */
    case BindingMismatch = 'binding_mismatch';

    /** The answer is wrong. */
    case Rejected = 'rejected';

    /** The prompt was already answered: the submission is a replay. */
    case Replay = 'replay';

    /** Too many attempts: the client must wait before trying again. */
    case Limited = 'limited';

    /** A store the challenge needs could not be reached, and the challenge requires it. */
    case Unavailable = 'unavailable';
}
