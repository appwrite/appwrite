import type { Answerable, AnswersQuestions} from '@serenity-js/core';
import { Expectation, ExpectationDetails, ExpectationMet, ExpectationNotMet } from '@serenity-js/core';

/**
 * Produces an [expectation](https://serenity-js.org/api/core/class/Expectation/) that negates the provided `expectation`.
 *
 * ## Ensuring that the actual value does not equal the expected value
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, equals, not } from '@serenity-js/assertions'
 *
 * const actual   = { name: 'apples' }
 * const expected = { name: 'bananas' }
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(actual, not(equals(expected))),
 * )
 * ```
 *
 * @param expectation
 *
 * @group Expectations
 */
export function not<Actual>(expectation: Expectation<Actual>): Expectation<Actual> {
    return new Not<Actual>(expectation);
}

/**
 * @package
 */
class Not<Actual> extends Expectation<Actual> {
    private static flipped(message: string): string {
        return message.startsWith('not ')
            ? message.slice(4)
            : `not ${ message }`;
    }

    constructor(private readonly expectation: Expectation<Actual>) {
        super(
            'not',
            Not.flipped(expectation.toString()),
            async (actor: AnswersQuestions, actual: Answerable<Actual>) => {
                const subject = Not.flipped(expectation.toString());

                const outcome = await actor.answer(expectation.isMetFor(actual));
                const expectationDetails = ExpectationDetails.of('not', outcome.expectation);

                return outcome instanceof ExpectationNotMet
                    ? new ExpectationMet(subject, expectationDetails, outcome.expected, outcome.actual )
                    : new ExpectationNotMet(subject, expectationDetails, outcome.expected, outcome.actual );
            }
        );
    }
}
