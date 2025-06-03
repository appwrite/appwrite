import type { JSONObject } from 'tiny-types';

import { ErrorSerialiser } from '../ErrorSerialiser';
import { RuntimeError } from './RuntimeError';

/**
 * Thrown to indicate that the test can't be performed due to an issue with a downstream dependency.
 * For example, it makes no sense to run a full-stack integration test if we already know that
 * the database server is down.
 *
 * ## Throwing a TestCompromisedError from a custom Interaction
 *
 * ```ts
 * import { Interaction } from '@serenity-js/core';
 *
 * const SetUpTestDatabase = () =>
 *   Interaction.where(`#actor sets up a test database`, actor => {
 *     return SomeCustomDatabaseSpecificAbility.as(actor).setUpTestDatabase().catch(error => {
 *       throw new TestCompromisedError('Could not set up the test database', error)
 *     })
 * })
 * ```
 *
 * @group Errors
 */
export class TestCompromisedError extends RuntimeError {

    static fromJSON(serialised: JSONObject): TestCompromisedError {
        const error = new TestCompromisedError(
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
        super(TestCompromisedError, message, cause);
    }
}
