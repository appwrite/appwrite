import type { Answerable, AnswersQuestions, ExpectationOutcome } from '@serenity-js/core';
import { Expectation, ExpectationMet, ExpectationNotMet } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when all the `expectations` are met for the given actual value.
 *
 * Use `and` to combine several expectations using logical "and",
 *
 * ## Combining several expectations
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, and, startsWith, endsWith } from '@serenity-js/assertions'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that('Hello World!', and(startsWith('Hello'), endsWith('!'))),
 * )
 * ```
 *
 * @param expectations
 *
 * @group Expectations
 */
export function and<Actual_Type>(...expectations: Array<Expectation<Actual_Type>>): Expectation<Actual_Type> {
    return new And(expectations);
}

/**
 * @package
 */
class And<Actual> extends Expectation<Actual> {
    private static readonly Separator = ' and ';

    constructor(private readonly expectations: Array<Expectation<Actual>>) {
        const description = expectations.map(expectation => expectation.toString()).join(And.Separator);

        super(
            'and',
            description,
            async (actor: AnswersQuestions, actual: Answerable<Actual>) => {
                let outcome: ExpectationOutcome;

                for (const expectation of expectations) {
                    outcome = await actor.answer(expectation.isMetFor(actual))

                    if (outcome instanceof ExpectationNotMet) {
                        return new ExpectationNotMet(description, outcome.expectation, outcome.expected, outcome.actual);
                    }
                }

                return new ExpectationMet(description, outcome?.expectation, outcome?.expected, outcome?.actual);
            }
        );
    }
}
