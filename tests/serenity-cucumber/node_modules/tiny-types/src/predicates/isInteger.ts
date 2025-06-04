import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is an integer {@link Number}.
 *
 * @example
 * import { ensure, isInteger, TinyType } from 'tiny-types';
 *
 * class AgeInYears extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('Age in years', value, isInteger());
 *     }
 * }
 *
 * @returns {Predicate<number>}
 */
export function isInteger(): Predicate<number> {
    return Predicate.to(`be an integer`, (value: number) =>
        typeof value === 'number' &&
        Number.isFinite(value) &&
        Math.floor(value) === value,
    );
}
