import { isArray } from './isArray';
import { isDefined } from './isDefined';
import { isGreaterThan } from './isGreaterThan';
import { Failure, Predicate, Result, Success } from './Predicate';

/**
 * @desc Ensures that the `value` meets all the provided {@link Predicate}s.
 *
 * @example
 * import { and, ensure, isDefined, isGreaterThan, isInteger, TinyType } from 'tiny-types';
 *
 * class AgeInYears extends TinyType {
 *     constructor(public readonly value: number) {
 *         ensure('AgeInYears', value, and(isDefined(), isInteger(), isGreaterThan(18));
 *     }
 * }
 *
 * @param {...Array<Predicate<T>>} predicates
 * @returns {Predicate<T>}
 */
export function and<T>(...predicates: Array<Predicate<T>>): Predicate<T> {
    return new And<T>(predicates);
}

/** @access private */
class And<T> extends Predicate<T> {

    constructor(private readonly predicates: Array<Predicate<T>>) {
        super();

        const results = [
            _ => isDefined().check(_),
            _ => isArray().check(_),
            _ => isGreaterThan(0).check(_.length),
        ];

        if (results.some(check => check(this.predicates) instanceof Failure)) {
            throw new Error(`Looks like you haven't specified any predicates to check the value against?`);
        }
    }

    /** @override */
    check(value: T): Result<T> {
        for (const predicate of this.predicates) {
            const result = predicate.check(value);
            if (result instanceof Failure) {
                return result;
            }
        }

        return new Success(value);
    }
}
