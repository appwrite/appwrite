import { types } from 'node:util';

export class ValueInspector {
    static isPromise(value: unknown): value is Promise<unknown> {
        return value instanceof Promise
            || (Object.prototype.hasOwnProperty.call(value, 'then'));
    }

    static isDate(value: unknown): value is Date {
        return value instanceof Date;
    }

    /**
     * Checks if the value is a named [`Function`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Function)
     *
     * @param value
     */
    static isFunction(value: unknown): value is Function {       // eslint-disable-line @typescript-eslint/ban-types
        return Object.prototype.toString.call(value) === '[object Function]';
    }

    /**
     * Checks if the value has a good chance of being a plain JavaScript object
     *
     * @param value
     */
    static isPlainObject(value: unknown): value is object {   // eslint-disable-line @typescript-eslint/ban-types

        // Basic check for Type object that's not null
        if (typeof value === 'object' && value !== null) {

            // If Object.getPrototypeOf supported, use it
            if (typeof Object.getPrototypeOf === 'function') {
                const proto = Object.getPrototypeOf(value);
                return proto === Object.prototype || proto === null;
            }

            // Otherwise, use internal class
            // This should be reliable as if getPrototypeOf not supported, is pre-ES5
            return Object.prototype.toString.call(value) === '[object Object]';
        }

        // Not an object
        return false;
    }

    /**
     * Returns true if `value` is a [JavaScript primitive](https://developer.mozilla.org/en-US/docs/Glossary/Primitive),
     * false otherwise.
     *
     * @param value
     */
    static isPrimitive(value: unknown): boolean {
        if (value === null) {
            return true;
        }

        return [
            'string',
            'number',
            'bigint',
            'boolean',
            'undefined',
            'symbol'
        ].includes(typeof value);
    }

    /**
     * Checks if the value defines its own `toString` method
     *
     * @param value
     */
    static hasItsOwnToString(value: unknown): boolean {
        return typeof value === 'object'
            && !! (value as any).toString
            && typeof (value as any).toString === 'function'
            && ! ValueInspector.isNative((value as any).toString);
    }

    /**
     * Describes the type of the provided value.
     *
     * @param value
     */
    static typeOf(value: unknown): string {
        switch (true) {
            case value === null:
                return 'null';
            case types.isProxy(value):
                return `Proxy<${ Reflect.getPrototypeOf(value as object).constructor.name }>`;
            case typeof value !== 'object':
                return typeof value;
            case value instanceof Date:
                return `Date`;
            case Array.isArray(value):
                return `Array`;
            case value instanceof RegExp:
                return `RegExp`
            case value instanceof Set:
                return 'Set';
            case value instanceof Map:
                return 'Map';
            case !! value.constructor && value.constructor !== Object:
                return value.constructor.name
            default:
                return 'object';
        }
    }

    /**
     * Inspired by https://davidwalsh.name/detect-native-function
     *
     * @param value
     */
    static isNative(value: unknown): value is Function {  // eslint-disable-line @typescript-eslint/ban-types

        const
            toString = Object.prototype.toString,           // Used to resolve the internal `Class` of values
            fnToString = Function.prototype.toString,       // Used to resolve the decompiled source of functions
            hostConstructor = /^\[object .+?Constructor]$/; // Used to detect host constructors (Safari > 4; really typed array specific)

        // Compile a regexp using a common native method as a template.
        // We chose `Object#toString` because there's a good chance it is not being mucked with.
        const nativeFunctionTemplate = new RegExp(
            '^' +
            // Coerce `Object#toString` to a string
            String(toString)
                // Escape any special regexp characters
                .replaceAll(/[$()*+./?[\\\]^{|}]/g, '\\$&')
                // Replace mentions of `toString` with `.*?` to keep the template generic.
                // Replace thing like `for ...` to support environments like Rhino which add extra info
                // such as method arity.
                .replaceAll(/toString|(function).*?(?=\\\()| for .+?(?=\\])/g, '$1.*?') +
            '$',
        );

        const type = typeof value;
        return type === 'function'
            // Use `Function#toString` to bypass the value's own `toString` method
            // and avoid being faked out.
            ? nativeFunctionTemplate.test(fnToString.call(value))
            // Fallback to a host object check because some environments will represent
            // things like typed arrays as DOM methods which may not conform to the
            // normal native pattern.
            : (value && type === 'object' && hostConstructor.test(toString.call(value))) || false;
    }

    /**
     * Checks if the value defines its own `inspect` method
     *
     * @param value
     */
    static isInspectable(value: unknown): value is { inspect: () => string } {
        return !! (value as any).inspect && typeof (value as any).inspect === 'function';
    }

    /**
     * Checks if the value defines its own [`inspect` method](https://nodejs.org/api/util.html#util_util_inspect_custom)
     *
     * @param value
     */
    static hasCustomInspectionFunction(value: unknown): value is object { // eslint-disable-line @typescript-eslint/ban-types
        return value && value[Symbol.for('nodejs.util.inspect.custom')];
    }
}
