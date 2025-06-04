import { ensure, isGreaterThanOrEqualTo, isInRange } from 'tiny-types';

import { AssertionError, ListItemNotFoundError, RaiseErrors, TimeoutExpiredError } from '../../../errors';
import { d } from '../../../io';
import type { UsesAbilities } from '../../abilities';
import type { Answerable } from '../../Answerable';
import { Interaction } from '../../Interaction';
import type { AnswersQuestions, Expectation, ExpectationOutcome} from '../../questions';
import { the } from '../../questions';
import { ExpectationMet } from '../../questions';
import { ScheduleWork } from '../abilities';
import { Duration } from '../models';

/**
 * `Wait` is a synchronisation statement that instructs the [actor](https://serenity-js.org/api/core/class/Actor/)
 * to wait before proceeding with their next [activity](https://serenity-js.org/api/core/class/Activity/),
 * either for a set [duration](https://serenity-js.org/api/core/class/Duration/), or until a given [expectation](https://serenity-js.org/api/core/class/Expectation/) is met.
 *
 * You can configure the timeout of the interaction to [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until):
 * - globally, using [`SerenityConfig.interactionTimeout`](https://serenity-js.org/api/core/class/SerenityConfig/#interactionTimeout)
 * - locally, on a per-interaction basis using [`Wait.upTo`](https://serenity-js.org/api/core/class/Wait/#upTo)
 *
 * :::tip Portable waiting
 * Serenity/JS implements `Wait` from scratch, so that the behaviour is consistent no matter the integration tool you use (Playwright, WebdriverIO, Selenium, etc.)
 * or the type of testing you do (Web, REST API, component testing, etc.)
 * :::
 *
 * ## Wait with Web-based tests
 *
 * ### Example widget
 *
 * ```html
 * <!--
 *     After about 1 second, the text will change from 'Loading...' to 'Ready!'
 * -->
 * <h1 id="status">Loading...</h1>
 * <script>
 *     (function () {
 *         setTimeout(function () {
 *             document.getElementById('status').textContent = 'Ready!'
 *         }, 1000);
 *     })();
 * </script>
 * ```
 *
 * ### Lean Page Object describing the widget
 *
 * ```ts
 * import { By, PageElement, Text } from '@serenity-js/web'
 *
 * class App {
 *   static status = () =>
 *      Text.of(PageElement.located(By.id('status'))
 *          .describedAs('status widget'))
 *  }
 * ```
 *
 * ### Waiting for a set duration
 *
 * ```ts
 * import { actorCalled, Duration, Wait } from '@serenity-js/core'
 * import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright'
 * import { Ensure, equals } from '@serenity-js/assertions'
 * import { Browser, chromium } from 'playwright'
 *
 * const browser = await chromium.launch({ headless: true })
 *
 * await actorCalled('Inês')
 *   .whoCan(BrowseTheWebWithPlaywright.using(browser))
 *   .attemptsTo(
 *       Wait.for(Duration.ofMilliseconds(1_500)),
 *       Ensure.that(App.status(), equals('Ready!')),
 *   );
 * ```
 *
 * **Please note** that while the above implementation works,
 * this approach is inefficient because at best
 * the actor might wait too long and at worst the test
 * might become "flaky" if any external interference
 * (like network glitches, animations taking a bit too long etc.)
 * makes the actor wait not long enough.
 *
 * ### Waiting until a condition is met
 *
 * ```ts
 * import { actorCalled, Wait } from '@serenity-js/core'
 * import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright'
 * import { equals } from '@serenity-js/assertions'
 * import { Browser, chromium } from 'playwright'
 *
 * const browser = await chromium.launch({ headless: true })
 *
 * await actorCalled('Wendy')
 *     .whoCan(BrowseTheWebWithPlaywright.using(browser))
 *     .attemptsTo(
 *         Wait.until(App.status(), equals('Ready!')),
 *         // app is ready, proceed with the scenario
 *     );
 * ```
 *
 * `Wait.until` makes the [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * keep asking the [`Question`](https://serenity-js.org/api/core/class/Question/),
 * in this case `Text.of(App.status)`, until the answer meets
 * the expectation, or a timeout expires (default: 5s).
 *
 * **Please note** that both Ensure and Wait can be used with
 * the same expectations, like `equals` or `includes`.
 *
 * ### Changing the default timeout
 *
 * ```ts
 *  import { actorCalled, Duration, Wait } from '@serenity-js/core';
 *  import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright';
 *  import { equals } from '@serenity-js/assertions';
 *  import { Browser, chromium } from 'playwright';
 *
 *  const browser: Browser = await chromium.launch({ headless: true });
 *
 *  await actorCalled('Polly')
 *      .whoCan(BrowseTheWebWithPlaywright.using(browser))
 *      .attemptsTo(
 *          Wait.upTo(Duration.ofSeconds(3))
 *              .until(App.status(), equals('Ready!')),
 *          // app is ready, proceed with the scenario
 *      );
 * ```
 *
 * ## Learn more
 * - [`SerenityConfig.interactionTimeout`](https://serenity-js.org/api/core/class/SerenityConfig/#interactionTimeout)
 * - [`Duration`](https://serenity-js.org/api/core/class/Duration/)
 * - [`Expectation`](https://serenity-js.org/api/core/class/Expectation/)
 *
 * @group Time
 */
export class Wait {

    /**
     * Minimum timeout that can be used with [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until),
     * defaults to 250 milliseconds,
     */
    static readonly minimumTimeout = Duration.ofMilliseconds(250);

    /**
     * The amount of time [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until) should wait between condition checks,
     * defaults to 500ms.
     *
     * Use [`WaitUntil.pollingEvery`](https://serenity-js.org/api/core/class/WaitUntil/#pollingEvery) to override it for a given interaction.
     *
     * @type {Duration}
     */
    static readonly defaultPollingInterval = Duration.ofMilliseconds(500);

    /**
     * Minimum polling interval of 50ms between condition checks, used with [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until).
     */
    static readonly minimumPollingInterval = Duration.ofMilliseconds(50);

    /**
     * Instantiates a version of this [`Interaction`](https://serenity-js.org/api/core/class/Interaction/)
     * configured to wait for a set duration.
     *
     * @param duration
     *  A set duration the [`Actor`](https://serenity-js.org/api/core/class/Actor/) should wait for before proceeding.
     */
    static for(duration: Answerable<Duration>): Interaction {
        return new WaitFor(duration);
    }

    /**
     * Instantiates a version of this [`Interaction`](https://serenity-js.org/api/core/class/Interaction/)
     * configured to wait until the answer to the question `actual` meets the `expectation`,
     * or the `timeout` expires.
     *
     * @param timeout
     *  Custom timeout to override [`SerenityConfig.interactionTimeout`](https://serenity-js.org/api/core/class/SerenityConfig/#interactionTimeout)
     */
    static upTo(timeout: Duration): { until: <Actual>(actual: Answerable<Actual>, expectation: Expectation<Actual>) => WaitUntil<Actual> } {
        return {
            until: <Actual>(actual: Answerable<Actual>, expectation: Expectation<Actual>): WaitUntil<Actual> =>
                new WaitUntil(actual, expectation, Wait.defaultPollingInterval.isLessThan(timeout) ? Wait.defaultPollingInterval : timeout, timeout),
        };
    }

    /**
     * Instantiates a version of this [`Interaction`](https://serenity-js.org/api/core/class/Interaction/) configured to
     * poll every [`Wait.defaultPollingInterval`](https://serenity-js.org/api/core/class/Wait/#defaultPollingInterval) for the result of the provided
     * question (`actual`) until it meets the `expectation`,
     * or the timeout expires.
     *
     * @param actual
     *  An [`Answerable`](https://serenity-js.org/api/core/#Answerable) that the [`Actor`](https://serenity-js.org/api/core/class/Actor/) will keep answering
     *  until the answer meets the [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) provided, or the timeout expires.
     *
     * @param expectation
     *  An [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) to be met before proceeding
     */
    static until<Actual>(actual: Answerable<Actual>, expectation: Expectation<Actual>): WaitUntil<Actual> {
        return new WaitUntil(actual, expectation, Wait.defaultPollingInterval);
    }
}

/**
 * @package
 */
class WaitFor extends Interaction {
    constructor(private readonly duration: Answerable<Duration>) {
        super(the`#actor waits for ${ duration }`);
    }

    async performAs(actor: UsesAbilities & AnswersQuestions): Promise<void> {
        const duration = await actor.answer(this.duration);

        return ScheduleWork.as(actor).waitFor(duration);
    }
}

/**
 * Synchronisation statement that instructs the [`Actor`](https://serenity-js.org/api/core/class/Actor/) to wait before proceeding until a given [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) is met.
 *
 * :::tip
 * To instantiate the [interaction](https://serenity-js.org/api/core/class/Interaction/) to [`WaitUntil`](https://serenity-js.org/api/core/class/WaitUntil/),
 * use the factory method [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until).
 * :::
 *
 * ## Learn more
 * * [`Wait.until`](https://serenity-js.org/api/core/class/Wait/#until)
 *
 * @group Time
 */
export class WaitUntil<Actual> extends Interaction {
    constructor(
        private readonly actual: Answerable<Actual>,
        private readonly expectation: Expectation<Actual>,
        private readonly pollingInterval: Duration,
        private readonly timeout?: Duration,
    ) {
        super(the`#actor waits until ${ actual } does ${ expectation }`);

        if (timeout) {
            ensure('Timeout', timeout.inMilliseconds(), isGreaterThanOrEqualTo(Wait.minimumTimeout.inMilliseconds()));
            ensure('Polling interval', pollingInterval.inMilliseconds(), isInRange(Wait.minimumPollingInterval.inMilliseconds(), timeout.inMilliseconds()));
        }

        ensure('Polling interval', pollingInterval.inMilliseconds(), isGreaterThanOrEqualTo(Wait.minimumPollingInterval.inMilliseconds()));
    }

    /**
     * Configure how frequently the [`Actor`](https://serenity-js.org/api/core/class/Actor/) should check if the answer meets the expectation.
     *
     * Note that the polling interval defines the delay between subsequent attempts
     * to evaluate the expected value, and doesn't include the amount of time
     * it take the actor to evaluate the value itself.
     *
     * @param interval
     */
    pollingEvery(interval: Duration): Interaction {
        return new WaitUntil(this.actual, this.expectation, interval, this.timeout);
    }

    /**
     * @inheritDoc
     */
    async performAs(actor: UsesAbilities & AnswersQuestions): Promise<void> {

        await ScheduleWork.as(actor).repeatUntil<ExpectationOutcome>(
            () => actor.answer(this.expectation.isMetFor(this.actual)),
            {
                exitCondition: outcome =>
                    outcome instanceof ExpectationMet,

                delayBetweenInvocations: (invocation) => invocation === 0
                    ? Duration.ofMilliseconds(0)
                    : this.pollingInterval,

                timeout: this.timeout,

                errorHandler: (error, outcome) => {
                    if (error instanceof ListItemNotFoundError) {
                        return; // ignore, lists might get populated later
                    }

                    if (error instanceof TimeoutExpiredError) {

                        throw RaiseErrors.as(actor).create(AssertionError, {
                            message: error.message + d` while waiting for ${ this.actual } to ${ this.expectation }`,
                            expectation: outcome?.expectation,
                            diff: outcome && { expected: outcome?.expected, actual: outcome?.actual },
                            location: this.instantiationLocation(),
                            cause: error,
                        });
                    }

                    throw error;
                }
            }
        );
    }
}
