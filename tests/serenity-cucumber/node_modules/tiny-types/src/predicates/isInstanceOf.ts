import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is an instance of `type`
 *
 * @example
 * import { ensure, isInstanceOf, TinyType } from 'tiny-types';
 *
 * class Birthday extends TinyType {
 *     constructor(public readonly value: Date) {
 *         ensure('Date', value, isInstanceOf(Date));
 *     }
 * }
 *
 * @param {Constructor<T>} type
 * @returns {Predicate<T>}
 */
export function isInstanceOf<T>(type: new (...args: any[]) => T): Predicate<T> {
    return Predicate.to(`be instance of ${type.name}`, (value: T) => value instanceof type);
}
