import { Predicate } from './Predicate';

/**
 * @desc Ensures that the `value` ends with a given suffix.
 *
 * @example
 * import { endsWith, ensure, TinyType } from 'tiny-types';
 *
 * class TextFileName extends TinyType {
 *     constructor(public readonly value: string) {
 *         super();
 *
 *         ensure('TextFileName', value, endsWith('.txt'));
 *     }
 * }
 *
 * @param {string} suffix
 *
 * @returns {Predicate<string>}
 */
export function endsWith(suffix: string): Predicate<string> {
    return Predicate.to(`end with '${ suffix }'`, (value: string) =>
        typeof value === 'string'
            && value.endsWith(suffix),
    );
}
