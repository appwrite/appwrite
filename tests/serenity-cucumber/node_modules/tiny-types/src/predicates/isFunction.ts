import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is a {@link Function}.
 *
 * @example
 * import { ensure, isFunction, TinyType } from 'tiny-types';
 *
 * function myFunction(callback: (error?: Error) => void): void {
 *     ensure('callback', callback, isFunction());
 * }
 *
 * @returns {Predicate<Function>}
 */
export function isFunction(): Predicate<(...args: any[]) => any> {
    return Predicate.to(`be a function`, (value: (...args: any[]) => any) =>
        typeof value === 'function'
    );
}
