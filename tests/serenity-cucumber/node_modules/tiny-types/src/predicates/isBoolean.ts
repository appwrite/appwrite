import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is a {@link Boolean} value.
 *
 * @example
 * import { ensure, isBoolean, TinyType } from 'tiny-types';
 *
 * class MarketingOptIn extends TinyType {
 *     constructor(public readonly value: boolean) {
 *         ensure('MarketingOptIn', value, isBoolean());
 *     }
 * }
 *
 * @returns {Predicate<boolean>}
 */
export function isBoolean(): Predicate<boolean> {
    return Predicate.to(`be a boolean value`, (value: boolean) => typeof value === 'boolean');
}
