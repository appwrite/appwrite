import { Predicate } from './Predicate';

/**
 * @desc
 *  Ensures that the `value` is not an empty string.
 *
 * @example
 * import { ensure, isString, TinyType } from 'tiny-types';
 *
 * class FirstName extends TinyType {
 *     constructor(public readonly value: string) {
 *         ensure('FirstName', value, isNotBlank());
 *     }
 * }
 *
 * @returns {Predicate<string>}
 */
export function isNotBlank(): Predicate<string> {
    return Predicate.to(`not be blank`, (value: string) => typeof value === 'string' && value !== '');
}
