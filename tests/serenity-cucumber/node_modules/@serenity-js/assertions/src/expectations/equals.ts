import { Expectation } from '@serenity-js/core';
import { equal } from 'tiny-types/lib/objects';

/**
 * Produces an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual value
 * is equal to the resolved value of `expectedValue`.
 *
 * Note that the equality check performs comparison **by value**
 * using [TinyTypes `equal`](https://github.com/jan-molak/tiny-types/blob/master/src/objects/equal.ts).
 *
 * ## Ensuring that the actual value equals expected value
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * const actual   = { name: 'apples' }
 * const expected = { name: 'apples' }
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(actual, equals(expected)),
 * )
 * ```
 *
 * @param expectedValue
 *
 * @group Expectations
 */
export const equals = Expectation.define(
    'equals', 'equal',
    <T>(actual: T, expected: T) =>
        equal(actual, expected),
);
