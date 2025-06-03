import type { JSONObject, JSONValue } from 'tiny-types';
import { ensure, isDefined, isFunction } from 'tiny-types';

/**
 * @group Errors
 */
export class ErrorSerialiser {
    private static readonly recognisedErrors: Array<new (...args: any[]) => Error> = [
        // Built-in JavaScript errors
        Error,
        EvalError,
        RangeError,
        ReferenceError,
        SyntaxError,
        TypeError,
        URIError,
    ];

    static serialise(error: Error): string {
        if (this.isSerialisable(error)) {
            return JSON.stringify(error.toJSON(), undefined, 0);
        }

        const name = error && error.constructor && error.constructor.name
            ? error.constructor.name
            : error.name;

        const serialisedError = Object.getOwnPropertyNames(error).reduce((serialised, key) => {
            serialised[key] = error[key]
            return serialised;
        }, { name }) as SerialisedError;

        return JSON.stringify(serialisedError, undefined, 0);
    }

    static registerErrorTypes(...types: Array<new (...args: any[]) => Error>): void {
        types.forEach(type => {
            ErrorSerialiser.recognisedErrors.push(
                ensure(`Error type ${ type }`, type as any, isDefined(), isFunction())
            );
        });
    }

    static deserialise(serialised?: string | JSONObject): Error | undefined {
        if (serialised === null || serialised === undefined) {
            return undefined;
        }

        const serialisedError = typeof serialised === 'string'
            ? JSON.parse(serialised) as SerialisedError
            : serialised;

        const constructor = ErrorSerialiser.recognisedErrors.find(errorType => errorType.name === serialisedError.name) || Error;

        if (this.isDeserialisable(constructor)) {
            return constructor.fromJSON(serialisedError) as Error;
        }

        const deserialised = Object.create(constructor.prototype);
        for (const property in serialisedError) {
            if (Object.prototype.hasOwnProperty.call(serialisedError, property)) {
                deserialised[property] = serialisedError[property];
            }
        }
        return deserialised;
    }

    private static isSerialisable(value: any): value is { toJSON: () => JSONValue } {
        return value
            && typeof (value as any).toJSON === 'function';
    }

    private static isDeserialisable<T>(type: new (...args: any[]) => T): type is typeof type & { fromJSON: (o: JSONValue) => T } {
        return type
            && typeof (type as any).fromJSON === 'function';
    }

    static deserialiseFromStackTrace(stack: string): Error {
        const stackTracePattern = /^([^\s:]*Error).*?(?::\s)?(.*?)\n(^ +at.*)$/ms;

        if (! stackTracePattern.test(stack)) {
            return new Error(String(stack));
        }

        const [, name, message, callStack_ ] = stack.match(stackTracePattern);

        return ErrorSerialiser.deserialise({ name, message: message.trim(), stack });
    }
}

interface SerialisedError extends JSONObject {
    /**
     *  Name of the constructor function used to instantiate
     *  the original [`Error`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Error) object.
     */
    name:    string;

    /**
     *  Message of the original [`Error`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Error) object
     */
    message: string;

    /**
     *  Stack trace of the original [`Error`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Error) object
     */
    stack:   string;
}
