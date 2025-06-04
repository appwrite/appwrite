import type { JSONValue } from 'tiny-types';

import { asyncMap, d } from '../../io';
import { ExpectationDetails, ExpectationMet, ExpectationNotMet } from '../';
import type { Answerable, AnswersQuestions, QuestionAdapter} from '../index';
import { the } from '../index';
import { Question } from '../Question';
import { Describable } from '../questions';
import type { ExpectationOutcome } from './expectations';

/**
 * @group Expectations
 */
export type Predicate<Actual> = (actor: AnswersQuestions, actual: Answerable<Actual>) =>
    Promise<ExpectationOutcome> | ExpectationOutcome;     // eslint-disable-line @typescript-eslint/indent

type AnswerableArguments<Arguments extends Array<unknown>> = { [Index in keyof Arguments]: Answerable<Arguments[Index]> };

/**
 * Defines an expectation to be used with [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until),
 * [`Check.whether`](https://serenity-js.org/api/core/class/Check/#whether),
 * [`Ensure.that`](https://serenity-js.org/api/assertions/class/Ensure/#that)
 * and as part of the Page Element Query Language with [`PageElements.where`](https://serenity-js.org/api/web/class/PageElements/#where)
 * and [`List.where`](https://serenity-js.org/api/core/class/List/#where).
 *
 * @group Expectations
 */
export class Expectation<Actual> extends Describable {

    /**
     * A factory method to that makes defining custom [expectations](https://serenity-js.org/api/core/class/Expectation/) easier
     *
     * #### Defining a custom expectation
     *
     * ```ts
     * import { Expectation } from '@serenity-js/core'
     * import { PageElement } from '@serenity-js/web'
     *
     * const isEmpty = Expectation.define(
     *   'isEmpty',         // name of the expectation function to be used when producing an AssertionError
     *   'become empty',    // human-readable description of the relationship between expected and actual values
     *   async (actual: PageElement) => {
     *     const value = await actual.value();
     *     return value.length === 0;
     *   }
     * )
     * ```
     *
     * #### Using a custom expectation in an assertion
     *
     * ```ts
     * import { Ensure } from '@serenity-js/assertions'
     * import { actorCalled } from '@serenity-js/core'
     * import { By, Clear, PageElement } from '@serenity-js/web'
     *
     * const nameField = () =>
     *   PageElement.located(By.css('[data-test-id="name"]')).describedAs('name field');
     *
     * await actorCalled('Izzy').attemptsTo(
     *   Clear.the(nameField()),
     *   Ensure.that(nameField(), isEmpty())
     * )
     * ```
     *
     * #### Using a custom expectation in a control flow statement
     *
     * ```ts
     * import { not } from '@serenity-js/assertions'
     * import { actorCalled, Check, Duration, Wait } from '@serenity-js/core'
     * import { By, PageElement } from '@serenity-js/web'
     *
     * const nameField = () =>
     *   PageElement.located(By.css('[data-test-id="name"]')).describedAs('name field');
     *
     * await actorCalled('Izzy').attemptsTo(
     *   Check.whether(nameField(), isEmpty())
     *     .andIfSo(
     *       Enter.theValue(actorInTheSpotlight().name).into(nameField()),
     *     ),
     * )
     * ```
     *
     * #### Using a custom expectation in a synchronisation statement
     *
     * ```ts
     * import { not } from '@serenity-js/assertions'
     * import { actorCalled, Duration, Wait } from '@serenity-js/core'
     * import { By, PageElement } from '@serenity-js/web'
     *
     * const nameField = () =>
     *   PageElement.located(By.css('[data-test-id="name"]')).describedAs('name field');
     *
     * await actorCalled('Izzy').attemptsTo(
     *   Enter.theValue(actorInTheSpotlight().name).into(nameField()),
     *
     *   Wait.upTo(Duration.ofSeconds(2))
     *     .until(nameField(), not(isEmpty())),
     * )
     * ```
     *
     * #### Learn more
     * - [`Ensure`](https://serenity-js.org/api/assertions/class/Ensure/)
     * - [`Check`](https://serenity-js.org/api/core/class/Check/)
     * - [`Wait`](https://serenity-js.org/api/core/class/Wait/)
     *
     * @param functionName
     *  Name of the expectation function to be used when producing an [`AssertionError`](https://serenity-js.org/api/core/class/AssertionError/)
     *
     * @param relationship
     *  Human-readable description of the relationship between the `expected` and the `actual` values.
     *  Used when reporting [activities](https://serenity-js.org/api/core/class/Activity/) performed by an [actor](https://serenity-js.org/api/core/class/Actor/)
     *
     * @param predicate
     */
    static define<Actual_Type, PredicateArguments extends Array<unknown>>(
        functionName: string,
        relationship: ((...answerableArguments: AnswerableArguments<PredicateArguments>) => Answerable<string>) | Answerable<string>,
        predicate: (actual: Actual_Type, ...predicateArguments: PredicateArguments) => Promise<boolean> | boolean,
    ): (...answerableArguments: AnswerableArguments<PredicateArguments>) => Expectation<Actual_Type>
    {
        return Object.defineProperty(function(...answerableArguments: AnswerableArguments<PredicateArguments>): Expectation<Actual_Type> {
            const description: Answerable<string> = typeof relationship === 'function' ? relationship(...answerableArguments)
                : (answerableArguments.length === 1 ? the`${ { toString: () => relationship } } ${ answerableArguments[0] }`
                    : relationship);

            return new Expectation<Actual_Type>(
                functionName,
                description,
                async (actor: AnswersQuestions, actualValue: Answerable<Actual_Type>): Promise<ExpectationOutcome> => {
                    const predicateArguments = await asyncMap(answerableArguments, answerableArgument =>
                        actor.answer(answerableArgument as Answerable<JSONValue>)
                    );

                    const actual    = await actor.answer(actualValue);

                    const result    = await predicate(actual, ...predicateArguments as PredicateArguments);

                    const descriptionText = await actor.answer(description);

                    const expectationDetails = ExpectationDetails.of(functionName, ...predicateArguments);

                    const expected = predicateArguments.length > 0
                        ? predicateArguments[0]
                        : true;     // the only parameter-less expectations are boolean ones like `isPresent`, `isActive`, etc.

                    return result
                        ? new ExpectationMet(descriptionText, expectationDetails, expected, actual)
                        : new ExpectationNotMet(descriptionText, expectationDetails, expected, actual);
                }
            )
        }, 'name', {value: functionName, writable: false});
    }

    /**
     * Used to define a simple [`Expectation`](https://serenity-js.org/api/core/class/Expectation/)
     *
     * #### Simple parameterised expectation
     *
     * ```ts
     *  import { actorCalled, Expectation } from '@serenity-js/core'
     *  import { Ensure } from '@serenity-js/assertions'
     *
     *  function isDivisibleBy(expected: Answerable<number>): Expectation<number> {
     *      return Expectation.thatActualShould<number, number>('have value divisible by', expected)
     *          .soThat((actualValue, expectedValue) => actualValue % expectedValue === 0);
     *  }
     *
     *  await actorCalled('Erica').attemptsTo(
     *      Ensure.that(4, isDivisibleBy(2)),
     *  )
     * ```
     *
     * @param relationshipName
     *  Name of the relationship between the `actual` and the `expected`. Use format `have value <adjective>`
     *  so that the description works in both positive and negative contexts, e.g. `Waited until 5 does have value greater than 2`,
     *  `Expected 5 to not have value greater than 2`.
     *
     * @param expectedValue
     */
    static thatActualShould<Expected_Type, Actual_Type>(relationshipName: string, expectedValue?: Answerable<Expected_Type>): {
        soThat: (simplifiedPredicate: (actualValue: Actual_Type, expectedValue: Expected_Type) => Promise<boolean> | boolean) => Expectation<Actual_Type>,
    } {
        return ({
            soThat: (simplifiedPredicate: (actualValue: Actual_Type, expectedValue: Expected_Type) => Promise<boolean> | boolean): Expectation<Actual_Type> => {
                const message = relationshipName + ' ' + d`${expectedValue}`;

                return new Expectation<Actual_Type>(
                    'unknown',
                    message,
                    async (actor: AnswersQuestions, actualValue: Answerable<Actual_Type>): Promise<ExpectationOutcome> => {
                        const expected  = await actor.answer(expectedValue);
                        const actual    = await actor.answer(actualValue);

                        const result    = await simplifiedPredicate(actual, expected);
                        const expectationDetails = ExpectationDetails.of('unknown');

                        return result
                            ? new ExpectationMet(message, expectationDetails, expected, actual)
                            : new ExpectationNotMet(message, expectationDetails, expected, actual);
                    }
                );
            },
        });
    }

    /**
     * Used to compose [expectations](https://serenity-js.org/api/core/class/Expectation/).
     *
     * #### Composing [expectations](https://serenity-js.org/api/core/class/Expectation/)
     *
     * ```ts
     * import { actorCalled, Expectation } from '@serenity-js/core'
     * import { Ensure, and, or, isGreaterThan, isLessThan, equals  } from '@serenity-js/assertions'
     *
     * function isWithin(lowerBound: number, upperBound: number) {
     *   return Expectation
     *     .to(`have value within ${ lowerBound } and ${ upperBound }`)
     *     .soThatActual(
     *       and(
     *         or(isGreaterThan(lowerBound), equals(lowerBound)),
     *         or(isLessThan(upperBound), equals(upperBound)),
     *       )
     *     )
     *  }
     *
     *  await actorCalled('Erica').attemptsTo(
     *      Ensure.that(5, isWithin(3, 6)),
     *  )
     * ```
     *
     * @param relationshipName
     *  Name of the relationship between the `actual` and the `expected`. Use format `have value <adjective>`
     *  so that the description works in both positive and negative contexts, e.g. `Waited until 5 does have value greater than 2`,
     *  `Expected 5 to not have value greater than 2`.
     */
    static to<Actual_Type>(relationshipName: string): {
        soThatActual: (expectation: Expectation<Actual_Type>) => Expectation<Actual_Type>,
    } {
        return {
            soThatActual: (expectation: Expectation<Actual_Type>): Expectation<Actual_Type> => {
                return new Expectation<Actual_Type>(
                    'unknown',
                    relationshipName,
                    async (actor: AnswersQuestions, actualValue: Answerable<Actual_Type>): Promise<ExpectationOutcome> => {
                        return await actor.answer(expectation.isMetFor(actualValue));
                    }
                );
            },
        };
    }

    protected constructor(
        private readonly functionName: string,
        description: Answerable<string>,
        private readonly predicate: Predicate<Actual>
    ) {
        super(description);
    }

    /**
     * Returns a [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter) that resolves to [`ExpectationOutcome`](https://serenity-js.org/api/core/class/ExpectationOutcome/)
     * indicating that the [expectation was met](https://serenity-js.org/api/core/class/ExpectationMet/)
     * or that the [expectation was not met](https://serenity-js.org/api/core/class/ExpectationNotMet/)
     *
     * @param actual
     */
    isMetFor(actual: Answerable<Actual>): QuestionAdapter<ExpectationOutcome> {
        return Question.about(this.getDescription(), actor => this.predicate(actor, actual));
    }

    /**
     * @inheritDoc
     */
    describedAs(description: Answerable<string>): this {
        super.setDescription(description);

        return this;
    }
}
