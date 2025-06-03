import type { Answerable, AnswersQuestions, ExpectationOutcome} from '@serenity-js/core';
import { d, Expectation, ExpectationDetails, ExpectationMet, ExpectationNotMet, Unanswered } from '@serenity-js/core';

/**
 * Produces an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when all the items of the actual array of `Item[]`
 * meet the `expectation`.
 *
 * ## Ensuring that all the items in an array meet the expectation
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, containItemsWhereEachItem, endsWith } from '@serenity-js/assertions'
 *
 * const items = [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday' ]
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(items, containItemsWhereEachItem(endsWith('day'))),
 * )
 * ```
 *
 * @param expectation
 *
 * @group Expectations
 */
export function containItemsWhereEachItem<Actual>(expectation: Expectation<Actual>): Expectation<Actual[]> {
    return new ContainItemsWhereEachItemMeetsExpectation(expectation);
}

/**
 * @package
 */
class ContainItemsWhereEachItemMeetsExpectation<Actual> extends Expectation<Actual[]> {

    private static descriptionFor(expectation: Expectation<any>) {
        return d`contain items where each item does ${ expectation }`;
    }

    constructor(private readonly expectation: Expectation<Actual>) {
        super(
            'containItemsWhereEachItem',
            ContainItemsWhereEachItemMeetsExpectation.descriptionFor(expectation),
            async (actor: AnswersQuestions, actual: Answerable<Actual[]>) => {

                const items: Actual[] = await actor.answer(actual);

                if (! items || items.length === 0) {
                    const unanswered = new Unanswered();
                    return new ExpectationNotMet(
                        ContainItemsWhereEachItemMeetsExpectation.descriptionFor(expectation),
                        ExpectationDetails.of('containItemsWhereEachItem', unanswered),
                        unanswered,
                        items,
                    );
                }

                let outcome: ExpectationOutcome;

                for (const item of items) {

                    outcome = await actor.answer(expectation.isMetFor(item))

                    if (outcome instanceof ExpectationNotMet) {
                        return new ExpectationNotMet(
                            ContainItemsWhereEachItemMeetsExpectation.descriptionFor(expectation),
                            ExpectationDetails.of('containItemsWhereEachItem', outcome.expectation),
                            outcome.expected,
                            items,
                        );
                    }
                }

                return new ExpectationMet(
                    ContainItemsWhereEachItemMeetsExpectation.descriptionFor(expectation),
                    ExpectationDetails.of('containItemsWhereEachItem', outcome.expectation),
                    outcome.expected,
                    items,
                );
            }
        );
    }
}
