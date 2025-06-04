import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/), a [`Task`](https://serenity-js.org/api/core/class/Task/) or a test scenario
 * can't be executed due to a logical error.
 *
 * For example, it's not possible to assert on the last HTTP Response if the request
 * hasn't been performed yet.
 *
 * @group Errors
 */
export class LogicError extends RuntimeError {

    static fromJSON(serialised: JSONObject): LogicError {
        const error = new LogicError(
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
        super(LogicError, message, cause);
    }
}
