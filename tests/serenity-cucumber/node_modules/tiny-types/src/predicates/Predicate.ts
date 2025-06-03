/**
 * @access public
 */
export abstract class Result<T> {
    constructor(public readonly value: T) {}
}

/**
 * @access public
 */
export class Success<T> extends Result<T> {}

/**
 * @access public
 */
export class Failure<T> extends Result<T> {
    constructor(value: T, public readonly description: string) {
        super(value);
    }
}

/**
 * @access public
 */
export type Condition<T> = (value: T) => boolean;

/**
 * @desc Describes a {@link Condition} that the `value` should meet.
 *
 * To define a custom predicate to be used with the {@link check} function
 * you can either extend the {@link Predicate}, or use the {@link Predicate.to} factory method.
 *
 * @example <caption>Assuming we'd like to create an isDefined() predicate:</caption>
 * ensure(`some value`, value, isDefined());
 *
 * @example <caption>We can either use the Predicate.to factory method:</caption>
 *
 * import { Predicate } from 'tiny-types';
 *
 * function isDefined<T>(): Predicate<T> {
 *     return Predicate.to(`be defined`, (value: T) =>
 *         ! (value === null || value === undefined),
 *     );
 * }
 *
 * @example <caption>or extend the Predicate itself</caption>
 *
 * import { Predicate, Result, Success, Failure } from 'tiny-types';
 *
 * function isDefined<T>() {
 *   return new IsDefined<T>();
 * }
 *
 * class IsDefined<T> extends Predicate<T> {
 *     check(value: T): Result<T> {
 *       return ! (value === null || value === undefined)
 *         ? new Success(value)
 *         : new Failure(value, `be defined`);
 *     }
 * }
 *
 * @access public
 */
export abstract class Predicate<T> {

    /**
     * @desc A factory method instantiating a single-condition predicate.
     * You can use it instead of extending the {Predicate} to save some keystrokes.
     *
     * @example
     * Predicate.to(`be defined`, (value: T) => ! (value === null || value === undefined));
     *
     * @param {string} description     - The description of the condition is used by {@link check} to generate the error
     *                                   message. The description should be similar to`be defined`,
     *                                   `be less than some value` for the error message to make sense.
     * @param {Condition<V>} condition - a function that takes a value of type `V` and returns a boolean
     *                                   indicating whether or not the condition is met. For example:
     *                                   `(value: V) => !! value`
     * @returns {Predicate<V>}
     *
     * @static
     */
    static to<V>(description: string, condition: Condition<V>): Predicate<V> {
        return new SingleConditionPredicate<V>(description, condition);
    }

    abstract check(value: T): Result<T>;
}

/**
 * @access private
 */
class SingleConditionPredicate<T> extends Predicate<T> {
    constructor(
        private readonly description: string,
        private readonly isMetBy: Condition<T>,
    ) {
        super();
    }

    /** @override */
    check(value: T): Result<T> {
        return this.isMetBy(value)
            ? new Success(value)
            : new Failure(value, this.description);
    }
}
