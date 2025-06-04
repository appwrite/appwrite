import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that a [scheduled operation](https://serenity-js.org/api/core/class/Scheduler/)
 * has been stopped before it was completed.
 *
 * @group Errors
 */
export class OperationInterruptedError extends RuntimeError {

    static fromJSON(serialised: JSONObject): OperationInterruptedError {
        const error = new OperationInterruptedError(
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
        super(OperationInterruptedError, message, cause);
    }
}
