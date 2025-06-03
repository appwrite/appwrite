import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` is a {@link Number}.
 *
 * @example
 * import { ensure, isNumber, TinyType } from 'tiny-types';
 *
 * class Percentage extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('Percentage', value, isNumber());
 *     }
 * }
 *
 * @returns {Predicate<number>}
 */
export function isNumber(): Predicate<number> {
    return Predicate.to(`be a number`, (value: number) => typeof value === 'number');
}
