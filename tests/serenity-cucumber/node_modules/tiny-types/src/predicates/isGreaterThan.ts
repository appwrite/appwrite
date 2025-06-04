import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is greater than the `lowerBound`.
 *
 * @example
 * import { ensure, isGreaterThan, TinyType } from 'tiny-types';
 *
 * class AgeInYears extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('Age in years', value, isGreaterThan(0));
 *     }
 * }
 *
 * @param {number} lowerBound
 * @returns {Predicate<number>}
 */
export function isGreaterThan(lowerBound: number): Predicate<number> {
    return Predicate.to(`be greater than ${ lowerBound }`, (value: number) =>
        typeof value === 'number' &&
        Number.isFinite(value) &&
        lowerBound < value,
    );
}
