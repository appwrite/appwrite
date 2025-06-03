import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that an unknown error has occurred.
 *
 * @group Errors
 */
export class UnknownError extends RuntimeError {

    static fromJSON(serialised: JSONObject): UnknownError {
        const error = new UnknownError(
            serialised.message as string,
            ErrorSerialiser.deserialise(serialised.cause as string | undefined),
        );

        error.stack = serialised.stack as string;

        return error;
    }

    /**
     * @param message - Human-readable description of the error
     * @param [cause] - The root cause of this [`RuntimeError`](https://serenity-js.org/api/core/class/RuntimeError/), if any
     */
    constructor(message: string, cause?: Error) {
        super(UnknownError, message, cause);
    }
}
