import { isRecord } from '../objects';
import { Predicate } from './Predicate';

/**
 * @desc
 *  Ensures that the `value` is a plain {@link Object}.
 *  Based on Jon Schlinkert's implementation.
 *
 * @see https://github.com/jonschlinkert/is-plain-object
 *
 * @example
 *  import { ensure, isPlainObject } from 'tiny-types';
 *
 *  ensure('plain object', {}, isPlainObject());
 *
 * @returns {Predicate<string>}
 */
export function isPlainObject<T extends object = object>(): Predicate<T> {
    return Predicate.to(`be a plain object`, (value: T) => isRecord(value));
}
