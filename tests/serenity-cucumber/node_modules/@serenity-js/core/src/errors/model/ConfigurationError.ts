import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that a test framework or test suite configuration error occurred.
 *
 * @group Errors
 */
export class ConfigurationError extends RuntimeError {

    static fromJSON(serialised: JSONObject): ConfigurationError {
        const error = new ConfigurationError(
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
        super(ConfigurationError, message, cause);
    }
}
