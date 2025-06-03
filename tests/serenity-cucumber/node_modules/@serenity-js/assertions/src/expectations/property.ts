import type { Answerable, AnswersQuestions} from '@serenity-js/core';
import { Expectation, ExpectationDetails, ExpectationMet, ExpectationNotMet } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the value of
 * the `actual[propertyName]` meets the `expectation`.
 *
 * ## Ensuring that an array has an item
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, property } from '@serenity-js/assertions'
 *
 * const list = [ 'hello', 'world' ]
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(list, property(0, isPresent())),
 * )
 * ```
 *
 * ## Ensuring that the property meets an expectation
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, property, equals } from '@serenity-js/assertions'
 *
 * const list = [ 'hello', 'world' ]
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(list, property('length', equals(2))),
 * )
 * ```
 *
 * ## Asserting on a list of objects
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, property, equals } from '@serenity-js/assertions'
 *
 * const developers = [{
 *     name: 'Jan',
 *     id: '@jan-molak',
 * }, {
 *     name: 'John',
 *     id: '@wakaleo',
 * }]
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(
 *     developers,
 *     containItemsWhereEachItem(
 *       property('id', startsWith('@'))
 *     ),
 *   ),
 * )
 * ```
 *
 * @param propertyName
 * @param expectation
 *
 * @group Expectations
 */
export function property<Actual extends object, PropertyName extends keyof Actual>(
    propertyName: PropertyName,
    expectation: Expectation<Actual[PropertyName]>
): Expectation<Actual> {
    return new HasProperty(propertyName, expectation);
}

/**
 * @package
 */
class HasProperty<Actual extends object, PropertyName extends keyof Actual> extends Expectation<Actual> {
    constructor(
        private readonly propertyName: PropertyName,
        expectation: Expectation<Actual[PropertyName]>,
    ) {
        const subject = `have property ${ String(propertyName) } that does ${ expectation }`;
        super(
            'property',
            subject,
            async (actor: AnswersQuestions, actual: Answerable<Actual>) => {
                const actualValue   = await actor.answer(actual);
                const outcome       = await actor.answer(expectation.isMetFor(actualValue[propertyName]));

                const expectationDetails = ExpectationDetails.of('property', String(propertyName), outcome.expectation);

                return outcome instanceof ExpectationMet
                    ? new ExpectationMet(subject, expectationDetails, outcome.expected, outcome.actual)
                    : new ExpectationNotMet(subject, expectationDetails, outcome.expected, outcome.actual);
            },
        );
    }
}
