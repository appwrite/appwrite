import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/), a [`Task`](https://serenity-js.org/api/core/class/Task/) or a test scenario
 * took longer to execute than the expected timeout.
 *
 * @group Errors
 */
export class TimeoutExpiredError extends RuntimeError {

    static fromJSON(serialised: JSONObject): TimeoutExpiredError {
        const error = new TimeoutExpiredError(
            serialised.message as string,
            ErrorSerialiser.deserialise(serialised.cause as string | undefined),
        );

        error.stack = serialised.stack as string;

        return error;
    }

    /**
     * @param message
     *  Human-readable description of the error
     *
     * @param [cause]
     *  The root cause of this [`RuntimeError`](https://serenity-js.org/api/core/class/RuntimeError/), if any
     */
    constructor(
        message: string,
        cause?: Error
    ) {
        super(TimeoutExpiredError, message, cause);
    }
}
