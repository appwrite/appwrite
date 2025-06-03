import { and } from './and';
import { isGreaterThanOrEqualTo } from './isGreaterThanOrEqualTo';
import { isLessThanOrEqualTo } from './isLessThanOrEqualTo';
import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is greater than or equal to the `lowerBound` and less than or equal to the `upperBound`
 *
 * @example
 * import { ensure, isInRange, TinyType } from 'tiny-types';
 *
 * class InvestmentLengthInYears extends TinyType {
 *     constructor(public readonly value: number) {
 *         super();
 *         ensure('InvestmentLengthInYears', value, isInRange(1, 5));
 *     }
 * }
 *
 * @param {number} lowerBound
 * @param {number} upperBound
 * @returns {Predicate<number>}
 */
export function isInRange(lowerBound: number, upperBound: number): Predicate<number> {
    return and(isGreaterThanOrEqualTo(lowerBound), isLessThanOrEqualTo(upperBound));
}
