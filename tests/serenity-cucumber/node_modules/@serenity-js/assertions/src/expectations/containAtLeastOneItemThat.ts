import type { Answerable, AnswersQuestions, ExpectationOutcome} from '@serenity-js/core';
import { d, Expectation, ExpectationDetails, ExpectationMet, ExpectationNotMet, Unanswered } from '@serenity-js/core';

/**
 * Produces an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual array of `Item[]` contains
 * at least one `Item` for which the `expectation` is met.
 *
 * ## Ensuring that at least one item in an array meets the expectation
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, containAtLeastOneItemThat, isGreaterThan } from '@serenity-js/assertions'
 *
 * const items = [ 10, 15, 20 ]
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(items, containAtLeastOneItemThat(isGreaterThan(18))),
 * )
 * ```
 *
 * @param expectation
 *
 * @group Expectations
 */
export function containAtLeastOneItemThat<Item>(expectation: Expectation<Item>): Expectation<Item[]> {
    return new ContainAtLeastOneItemThatMeetsExpectation(expectation);
}

/**
 * @package
 */
class ContainAtLeastOneItemThatMeetsExpectation<Item> extends Expectation<Item[]> {

    private static descriptionFor(expectation: Expectation<any>) {
        return d`contain at least one item that does ${ expectation }`;
    }

    constructor(private readonly expectation: Expectation<Item>) {
        super(
            'containAtLeastOneItemThat',
            ContainAtLeastOneItemThatMeetsExpectation.descriptionFor(expectation),
            async (actor: AnswersQuestions, actual: Answerable<Item[]>) => {

                const items: Item[] = await actor.answer(actual);

                if (! items || items.length === 0) {
                    const unanswered = new Unanswered();
                    return new ExpectationNotMet(
                        ContainAtLeastOneItemThatMeetsExpectation.descriptionFor(expectation),
                        ExpectationDetails.of('containAtLeastOneItemThat', unanswered),
                        unanswered,
                        items,
                    );
                }

                let outcome: ExpectationOutcome;

                for (const item of items) {

                    outcome = await actor.answer(expectation.isMetFor(item))

                    if (outcome instanceof ExpectationMet) {
                        return new ExpectationMet(
                            ContainAtLeastOneItemThatMeetsExpectation.descriptionFor(expectation),
                            ExpectationDetails.of('containAtLeastOneItemThat', outcome.expectation),
                            outcome.expected,
                            items,
                        );
                    }
                }

                return new ExpectationNotMet(
                    ContainAtLeastOneItemThatMeetsExpectation.descriptionFor(expectation),
                    ExpectationDetails.of('containAtLeastOneItemThat', outcome.expectation),
                    outcome.expected,
                    items,
                );
            }
        );
    }
}
