import { Expectation } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual `string` value
 * matches the `expected` regular expression.
 *
 * ## Ensuring that a given string matches a regular expression
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, includes } from '@serenity-js/assertions'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that('Hello World!', matches(/[Ww]orld/)),
 * )
 * ```
 *
 * @param expected
 *
 * @group Expectations
 */
export const matches = Expectation.define(
    'matches', 'match',
    (actual: string, expected: RegExp) =>
        expected.test(actual),
);
