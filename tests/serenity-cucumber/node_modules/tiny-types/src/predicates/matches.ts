import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` matches {@link RegExp}.
 *
 * @example
 * import { ensure, matches, TinyType } from 'tiny-types';
 *
 *
 * class CompanyEmailAddress extends TinyType {
 *     constructor(public readonly value: string) {
 *         super();
 *         ensure('EmailAddress', value, matches(/[a-z]+\.[a-z]+@example\.org/));
 *     }
 * }
 *
 * @param {RegExp} expression
 *
 * @returns {Predicate<string>}
 */
export function matches(expression: RegExp): Predicate<string> {
    return Predicate.to(`match pattern ${ expression }`, (value: string) => expression.test(value));
}
