import { Constructor } from '../types';

export type Logger = (_: string) => void;

/**
 * @desc A decorator to mark a class, method or function as deprecated and make it log a warning whenever it is used.
 * Please see the tests for examples of usage.
 *
 * @param {string} message - describes the alternative implementation that should be used instead
 *                  of the deprecated method/function/class
 * @param {Logger} log - a function that handles the printing of the message,
 *                  such as {@link console.warn}
 */
export function deprecated(message = '', log: Logger = console.warn): (target: any, propertyKey?: string, descriptor?: any) => any {    // tslint:disable-line:no-console
    // eslint-disable-next-line unicorn/consistent-function-scoping,no-prototype-builtins
    const hasPrototype = (target: { hasOwnProperty(_: string): boolean }): boolean => target.hasOwnProperty('prototype');

    return (target: any, propertyKey?: string, descriptor?: any): any => {         // tslint:disable-line:ban-types
        if (target && propertyKey && descriptor) {
            return deprecateMethod(message, target, propertyKey, descriptor, log);
        }
        else if (hasPrototype(target)) {
            return deprecateClass(message, target, log);
        }
        else {
            throw new Error(`Only a class, method or function can be marked as deprecated. ${typeof target} given.`);
        }
    };
}

function deprecateClass(message: string, target: Constructor<any>, log: (...args: any[]) => void): Constructor<any> {
    return class extends target {
        constructor(...args: any[]) {
            log(`${target.name} has been deprecated. ${ message }`.trim());

            super(...args);
        }
    };
}

function deprecateMethod<T extends object>(message: string, target: T, propertyKey: string, descriptor: any, log: (...args: any[]) => void) {
    const originalMethod = descriptor.value;

    descriptor.value = function (...args: any[]) {
        log(`${target.constructor.name}#${propertyKey} has been deprecated. ${ message }`.trim());

        return originalMethod.apply(this, args);
    };

    return descriptor;
}
