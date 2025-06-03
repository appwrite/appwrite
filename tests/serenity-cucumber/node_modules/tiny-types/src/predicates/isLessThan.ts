import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is less than the `upperBound`.
 *
 * @example
 * import { ensure, isLessThan, TinyType } from 'tiny-types';
 *
 * class InvestmentPeriodInYears extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('Investment period in years', value, isLessThan(50));
 *     }
 * }
 *
 * @param {number} upperBound
 * @returns {Predicate<number>}
 */
export function isLessThan(upperBound: number): Predicate<number> {
    return Predicate.to(`be less than ${ upperBound }`, (value: number) =>
        typeof value === 'number' &&
        Number.isFinite(value) &&
        value < upperBound,
    );
}
