import { Expectation } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual value of type `number`
 * is less than the expected `number`.
 *
 * ## Ensuring that a given number is less than the expected number
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, isLessThan } from '@serenity-js/assertions'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(5, isLessThan(10)),
 * )
 * ```
 *
 * ## Ensuring that a given number is within the expected range
 *
 * ```ts
 * import { actorCalled, Expectation, d } from '@serenity-js/core'
 * import { Ensure, and, equals, isGreaterThan, isLessThan, or } from '@serenity-js/assertions'
 *
 * const isWithinRange = (lowerBound: Answerable<number>, upperBound: Answerable<number>) =>
 *   Expectation.to(d`have value that is between ${ lowerBound } and ${ upperBound }`)
 *     .soThatActual(
 *       and(
 *         or(equals(lowerBound), isGreaterThan(lowerBound)),
 *         or(equals(upperBound), isLessThan(upperBound)),
 *       )
 *     ),
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(
 *     7,
 *     isWithinRange(5, 10)
 *   ),
 * )
 * ```
 *
 * @param expected
 *
 * @group Expectations
 */
export const isLessThan = Expectation.define(
    'isLessThan', `have value that's less than`,
    (actual: number, expected: number) =>
        actual < expected,
);
