import type {
    Answerable,
    AnswersQuestions,
    CollectsArtifacts,
    Expectation,
    RuntimeError,
    UsesAbilities
} from '@serenity-js/core';
import {
    Activity,
    AssertionError,
    ExpectationMet,
    ExpectationNotMet,
    f,
    Interaction,
    LogicError,
    Question,
    RaiseErrors,
    the
} from '@serenity-js/core';
import type { FileSystemLocation } from '@serenity-js/core/lib/io';

import { EnsureEventually } from './EnsureEventually';

/**
 * The [interaction](https://serenity-js.org/api/core/class/Interaction/) to `Ensure`
 * verifies if the resolved value of the provided [`Answerable`](https://serenity-js.org/api/core/#Answerable)
 * meets the specified [`Expectation`](https://serenity-js.org/api/core/class/Expectation/).
 * If not, it throws an [`AssertionError`](https://serenity-js.org/api/core/class/AssertionError/).
 *
 * Use `Ensure` to verify the state of the system under test.
 *
 * ## Basic usage with static values
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * await actorCalled('Erica').attemptsTo(
 *   Ensure.that('Hello world!', equals('Hello world!'))
 * )
 * ```
 *
 * ## Composing expectations with `and`
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { and, Ensure, startsWith, endsWith } from '@serenity-js/assertions'
 *
 * await actorCalled('Erica').attemptsTo(
 *   Ensure.that('Hello world!', and(startsWith('Hello'), endsWith('!'))
 * )
 * ```
 *
 * ## Overriding the type of Error thrown upon assertion failure
 *
 * ```ts
 * import { actorCalled, TestCompromisedError } from '@serenity-js/core'
 * import { and, Ensure, startsWith, endsWith } from '@serenity-js/assertions'
 * import { CallAnApi, GetRequest, LastResponse, Send } from '@serenity-js/rest'
 *
 * await actorCalled('Erica')
 *   .whoCan(CallAnApi.at('https://example.com'))
 *   .attemptsTo(
 *     Send.a(GetRequest.to('/api/health')),
 *     Ensure.that(LastResponse.status(), equals(200))
 *       .otherwiseFailWith(TestCompromisedError, 'The server is down, please cheer it up!')
 *   )
 * ```
 *
 * @group Activities
 */
export class Ensure<Actual> extends Interaction {

    /**
     * Creates an [interaction](https://serenity-js.org/api/core/class/Interaction/) to `Ensure`, which
     * verifies if the resolved value of the provided [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     * meets the specified [`Expectation`](https://serenity-js.org/api/core/class/Expectation/).
     * If not, it immediately throws an [`AssertionError`](https://serenity-js.org/api/core/class/AssertionError/).
     *
     * @param {Answerable<Actual_Type>} actual
     *  An [`Answerable`](https://serenity-js.org/api/core/#Answerable) describing the actual state of the system.
     *
     * @param {Expectation<Actual_Type>} expectation
     *  An [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) you expect the `actual` value to meet
     *
     * @returns {Ensure<Actual_Type>}
     */
    static that<Actual_Type>(actual: Answerable<Actual_Type>, expectation: Expectation<Actual_Type>): Ensure<Actual_Type> {
        return new Ensure(actual, expectation, Activity.callerLocation(5));
    }

    /**
     * Creates an [interaction](https://serenity-js.org/api/core/class/Interaction/) to [`EnsureEventually`](https://serenity-js.org/api/assertions/class/EnsureEventually/),
     * which verifies if the resolved value of the provided [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     * meets the specified [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) within the expected timeframe.
     *
     * If the expectation is not met by the time the timeout expires, the interaction throws an [`AssertionError`](https://serenity-js.org/api/core/class/AssertionError/).
     *
     * @param {Answerable<Actual_Type>} actual
     *  An [`Answerable`](https://serenity-js.org/api/core/#Answerable) describing the actual state of the system.
     *
     * @param {Expectation<Actual_Type>} expectation
     *  An [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) you expect the `actual` value to meet
     *
     * @returns {Ensure<Actual_Type>}
     */
    static eventually<Actual_Type>(actual: Answerable<Actual_Type>, expectation: Expectation<Actual_Type>): EnsureEventually<Actual_Type> {
        return new EnsureEventually(actual, expectation, Activity.callerLocation(5));
    }

    /**
     * @param actual
     * @param expectation
     * @param location
     */
    private constructor(
        protected readonly actual: Answerable<Actual>,
        protected readonly expectation: Expectation<Actual>,
        location: FileSystemLocation,
    ) {
        super(the`#actor ensures that ${ actual } does ${ expectation }`, location);
    }

    /**
     * @inheritDoc
     */
    async performAs(actor: UsesAbilities & AnswersQuestions & CollectsArtifacts): Promise<void> {
        const outcome = await actor.answer(this.expectation.isMetFor(this.actual));

        if (outcome instanceof ExpectationNotMet) {
            const actualDescription = this.actual === undefined
                ? 'undefined'
                : Question.formattedValue().of(this.actual);

            const message = `Expected ${ actualDescription } to ${ outcome.message }`;

            throw RaiseErrors.as(actor).create(AssertionError, {
                message,
                expectation: outcome.expectation,
                diff: { expected: outcome.expected, actual: outcome.actual },
                location: this.instantiationLocation(),
            });
        }

        if (! (outcome instanceof ExpectationMet)) {
            throw new LogicError(f`Expectation#isMetFor(actual) should return an instance of an ExpectationOutcome, not ${ outcome }`);
        }
    }

    /**
     * Overrides the default [`AssertionError`](https://serenity-js.org/api/core/class/AssertionError/) thrown when
     * the actual value does not meet the expectation.
     *
     * @param typeOfRuntimeError
     *  A constructor function producing a subtype of [`RuntimeError`](https://serenity-js.org/api/core/class/RuntimeError/) to throw, e.g. [`TestCompromisedError`](https://serenity-js.org/api/core/class/TestCompromisedError/)
     *
     * @param message
     *  The message explaining the failure
     */
    otherwiseFailWith(typeOfRuntimeError: new (message: string, cause?: Error) => RuntimeError, message?: string): Interaction {
        const location = this.instantiationLocation();

        return Interaction.where(this.toString(), async actor => {
            try {
                await this.performAs(actor);
            }
            catch (error) {
                throw RaiseErrors.as(actor).create(typeOfRuntimeError, {
                    message: message ?? error.message,
                    location,
                    cause: error
                });
            }
        });
    }
}
