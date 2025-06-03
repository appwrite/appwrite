import { TinyType } from 'tiny-types';

/**
 * Base class for custom errors that may occur during execution of a test scenario.
 *
 * ## Defining a custom error
 *
 * ```ts
 * import { RuntimeError } from '@serenity-js/core'
 *
 * export class CustomError extends RuntimeError {
 *   constructor(message: string, cause?: Error) {
 *       super(CustomError, message, cause);
 *   }
 * }
 * ```
 *
 * ## Wrapping a sync error
 *
 * ```ts
 * try {
 *     operationThatMightThrowAnError();
 * } catch(error) {
 *     // catch and re-throw
 *     throw new CustomError('operationThatMightThrowAnError has failed', error);
 * }
 * ```
 *
 * ## Wrapping an async error
 *
 * ```ts
 * operationThatMightRejectAPromise()
 *   .catch(error => {
 *     // catch and re-throw
 *     throw new CustomError('operationThatMightThrowAnError has failed', error)
 *   })
 * ```
 *
 * ## Registering a custom error with [`ErrorSerialiser`](https://serenity-js.org/api/core/class/ErrorSerialiser/)
 *
 * ```ts
 * import { RuntimeError } from '@serenity-js/core'
 * import { ErrorSerialiser } from '@serenity-js/core/lib/io'
 *
 * export class CustomError extends RuntimeError {
 *
 *    static fromJSON(serialised: JSONObject): CustomError {
 *         const error = new CustomError(
 *             serialised.message as string,
 *             ErrorSerialiser.deserialise(serialised.cause as string | undefined),
 *         );
 *
 *         error.stack = serialised.stack as string;
 *
 *         return error;
 *     }
 *
 *   constructor(message: string, cause?: Error) {
 *       super(CustomError, message, cause);
 *   }
 * }
 *
 * ErrorSerialiser.registerErrorTypes(CustomError)
 * ```
 *
 * @group Errors
 */
export abstract class RuntimeError extends Error {

    /**
     * @param type - Constructor function used to instantiate a subclass of a RuntimeError
     * @param message - Human-readable description of the error
     * @param [cause] - The root cause of this [`RuntimeError`](https://serenity-js.org/api/core/class/RuntimeError/), if any
     */
    protected constructor(
        type: new (...args: any[]) => RuntimeError,
        message: string,
        public readonly cause?: Error,
    ) {
        const errorMessage = message || '';
        const isMultiline = errorMessage.includes('\n');
        const causeAlreadyIncluded = cause?.message && errorMessage.includes(cause.message);

        super(
            isMultiline || causeAlreadyIncluded
                ? errorMessage
                : errorMessage + (cause && cause?.message ? `; ${ cause.message }` : '')
        );

        Object.setPrototypeOf(this, type.prototype);
        this.name = this.constructor.name;

        Error.captureStackTrace(this, type);

        if (cause) {
            this.stack = `${ this.stack }\nCaused by: ${ cause.stack }`;
        }
    }

    /**
     * Human-readable description of the error
     */
    toString(): string {
        return `${ this.constructor.name }: ${ this.message }`;
    }

    toJSON(): object {
        return TinyType.prototype.toJSON.apply(this)
    }
}
