import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/), a [`Task`](https://serenity-js.org/api/core/class/Task/) or a test scenario
 * can't be executed due to no items are found in a list.
 *
 * For example, it's not possible to get the first() or the last() item of a list
 * if the list is empty.
 *
 * @group Errors
 */
export class ListItemNotFoundError extends RuntimeError {

    static fromJSON(serialised: JSONObject): ListItemNotFoundError {
        const error = new ListItemNotFoundError(
            serialised.message as string,
            ErrorSerialiser.deserialise(serialised.cause as string | undefined),
        );

        error.stack = serialised.stack as string;

        return error;
    }

    /**
     * @param message - Human-readable description of the error
     * @param [cause] - The root cause of this [`ListItemNotFoundError`](https://serenity-js.org/api/core/class/ListItemNotFoundError/), if any
     */
    constructor(message: string, cause?: Error) {
        super(ListItemNotFoundError, message, cause);
    }
}
