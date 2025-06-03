import { d } from '../../io';
import type { PerformsActivities } from '../activities';
import type { Activity } from '../Activity';
import type { Answerable } from '../Answerable';
import type { AnswersQuestions } from '../questions';
import { Task } from '../Task';
import type { Expectation } from './Expectation';
import { ExpectationMet } from './expectations';

/**
 * A [flow control statement](https://en.wikipedia.org/wiki/Control_flow)
 * that enables an [`Actor`](https://serenity-js.org/api/core/class/Actor/) to decide between two alternate series of [activities](https://serenity-js.org/api/core/class/Activity/).
 *
 * Think of it as a Screenplay Pattern equivalent of the traditional `if` statement.
 *
 * ## Choose between two alternative sequences of activities
 *
 * ```ts
 * import { equals } from '@serenity-js/assertions'
 * import { actorCalled, Check } from '@serenity-js/core'
 *
 * await actorCalled('Chuck').attemptsTo(
 *   Check.whether(process.env.MODE, equals('prod'))
 *     .andIfSo(
 *       LogInAsProdUser(),
 *     )
 *     .otherwise(
 *       LogInAsTestUser(),
 *     )
 * )
 * ```
 *
 * ## Perform a sequence of activities when a condition is met
 *
 * ```ts
 * import { actorCalled, Check } from '@serenity-js/core'
 * import { isVisible } from '@serenity-js/web'
 *
 * await actorCalled('Chuck').attemptsTo(
 *   Check.whether(CookieConsentBanner(), isVisible())
 *     .andIfSo(
 *         AcceptNecessaryCookies(),
 *     )
 * )
 * ```
 *
 * @group Activities
 */
export class Check<Actual> extends Task {
    // eslint-disable-next-line @typescript-eslint/explicit-module-boundary-types
    static whether<Actual_Type>(actual: Answerable<Actual_Type>, expectation: Expectation<Actual_Type>): { andIfSo: (...activities: Activity[]) => Check<Actual_Type> } {
        return {
            andIfSo: (...activities: Activity[]) =>
                new Check(actual, expectation, activities),
        };
    }

    protected constructor(
        private readonly actual: Answerable<Actual>,
        private readonly expectation: Expectation<Actual>,
        private readonly activities: Activity[],
        private readonly alternativeActivities: Activity[] = [],
    ) {
        super(d`#actor checks whether ${ actual } does ${ expectation }`);
    }

    /**
     * @param alternativeActivities
     *  A sequence of [activities](https://serenity-js.org/api/core/class/Activity/) to perform when the [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) is not met.
     */
    otherwise(...alternativeActivities: Activity[]): Task {
        return new Check<Actual>(this.actual, this.expectation, this.activities, alternativeActivities);
    }

    /**
     * @inheritDoc
     */
    async performAs(actor: AnswersQuestions & PerformsActivities): Promise<void> {
        const outcome = await actor.answer(this.expectation.isMetFor(this.actual));

        return outcome instanceof ExpectationMet
            ? actor.attemptsTo(...this.activities)
            : actor.attemptsTo(...this.alternativeActivities);
    }
}
