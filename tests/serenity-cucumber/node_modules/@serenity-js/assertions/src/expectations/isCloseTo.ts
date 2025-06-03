import { d, Expectation } from '@serenity-js/core';

/**
 * Produces an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual value
 * is within a given ± `absoluteTolerance` range of the `expected` value.
 *
 * ## Ensuring that a given floating point number is close to the expected number
 *
 * ```ts
 *  import { actorCalled } from '@serenity-js/core'
 *  import { Ensure, isCloseTo } from '@serenity-js/assertions'
 *
 *  await actorCalled('Iris').attemptsTo(
 *      Ensure.that(10.123, isCloseTo(10, 0.2))
 *  )
 * ```
 *
 * @param expected
 * @param [absoluteTolerance=1e-9]
 *  Absolute ± tolerance range, defaults to `1e-9`
 *
 * @group Expectations
 */
export const isCloseTo = Expectation.define(
    'isCloseTo', (expected, absoluteTolerance = 1e-9) => d`have value close to ${ expected } ±${ absoluteTolerance }`,
    // eslint-disable-next-line @typescript-eslint/no-inferrable-types
    (actual: number, expected: number, absoluteTolerance: number = 1e-9) => {
        // short-circuit exact equality
        if (actual === expected) {
            return true;
        }

        if (! (Number.isFinite(actual) && Number.isFinite(expected))) {
            return false;
        }

        const difference = Math.abs(actual - expected)

        return difference <= absoluteTolerance;
    }
)
