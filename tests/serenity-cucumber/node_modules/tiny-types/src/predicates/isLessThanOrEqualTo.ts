import { isEqualTo } from './isEqualTo';
import { isLessThan } from './isLessThan';
import { or } from './or';
import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is less than or equal to the `upperBound`.
 *
 * @example
 * import { ensure, isLessThanOrEqualTo, TinyType } from 'tiny-types';
 *
 * class InvestmentPeriod extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('InvestmentPeriod', value, isLessThanOrEqualTo(50));
 *     }
 * }
 *
 * @param {number} upperBound
 * @returns {Predicate<number>}
 */
export function isLessThanOrEqualTo(upperBound: number): Predicate<number> {
    return or(isLessThan(upperBound), isEqualTo(upperBound));
}
