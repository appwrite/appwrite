import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is an {@link Array}.
 *
 * @example
 * import { ensure, isArray, TinyType, TinyTypeOf } from 'tiny-types';
 *
 * class Name extends TinyTypeOf<string>() {}
 *
 * class Names extends TinyType {
 *   constructor(public readonly values: Name[]) {
 *      super();
 *      ensure('Names', values, isArray());
 *   }
 * }
 *
 * @returns {Predicate<T[]>}
 */
export function isArray<T>(): Predicate<T[]> {
    return Predicate.to(`be an array`, (value: T[]) => Array.isArray(value));
}
