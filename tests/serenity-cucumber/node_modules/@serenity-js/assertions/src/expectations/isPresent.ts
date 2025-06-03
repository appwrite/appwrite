import type { Answerable, AnswersQuestions, Optional } from '@serenity-js/core';
import { Expectation, ExpectationDetails, ExpectationMet, ExpectationNotMet } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the `actual` value is not undefined or null.
 *
 * Also, when the `actual` implements [`Optional`](https://serenity-js.org/api/core/interface/Optional/),
 * the expectation is met when calling [`Optional.isPresent`](https://serenity-js.org/api/core/interface/Optional/#isPresent)
 * returns an [`Answerable`](https://serenity-js.org/api/core/#Answerable) that resolves to `true`
 *
 * ## Ensuring that a value is defined
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { CallAnApi, Send, GetRequest, LastResponse } from '@serenity-js/rest'
 * import { Ensure, isPresent } from '@serenity-js/assertions'
 *
 * interface Product {
 *     name: string;
 * }
 *
 * interface ProductsResponse {
 *     products: Product[];
 * }
 *
 * await actorCalled('Apisitt')
 *   .whoCan(CallAnApi.at('https://api.example.org'))
 *   .attemptsTo(
 *     Send.a(GetRequest.to('/products')),
 *     Ensure.that(LastResponse.body<ProductsResponse>().products[0], isPresent()),
 *   )
 * ```
 *
 * ## Checking if a PageElement is present
 *
 * ```ts
 * import { actorCalled, Check } from '@serenity-js/core';
 * import { BrowseTheWebWithPlaywright } from '@serenity-js/playwright';
 * import { By, Click, Navigate, PageElement } from '@serenity-js/web';
 * import { Browser, chromium } from 'playwright';
 *
 * class NewsletterSubscription {
 *   static modal = () =>
 *     PageElement.located(By.id('newsletter-subscription'))
 *       .describedAs('newsletter subscription modal')
 *
 *   static closeButton = () =>
 *     PageElement.located(By.class('.close'))
 *       .of(NewsletterSubscription.modal())
 *       .describedAs('close button')
 * }
 *
 * const browser = await chromium.launch({ headless: true });
 *
 * await actorCalled('Isabela')
 *   .whoCan(BrowseTheWebWithPlaywright.using(browser))
 *   .attemptsTo(
 *     Navigate.to(`https://example.org`),
 *     Check.whether(NewsletterSubscription.modal(), isPresent())
 *       .andIfSo(Click.on(NewsletterSubscription.closeButton())),
 *   )
 * ```
 *
 * @group Expectations
 */
export function isPresent<Actual>(): Expectation<Actual> {
    return new IsPresent<Actual>();
}

class IsPresent<Actual> extends Expectation<Actual> {
    private static isOptional(value: any): value is Optional {
        return value !== undefined
            && value !== null
            && typeof value.isPresent === 'function';
    }

    private static valueToCheck<A>(actual: Answerable<A>, actor: AnswersQuestions): Answerable<A> {
        if (IsPresent.isOptional(actual)) {
            return actual;
        }

        return actor.answer(actual);
    }

    private static async isPresent<A>(value: Answerable<A>, actor: AnswersQuestions): Promise<boolean> {
        if (IsPresent.isOptional(value)) {
            return actor.answer(value.isPresent());
        }

        return value !== undefined
            && value !== null;
    }

    constructor() {
        super(
            'isPresent',
            'become present',
            async (actor: AnswersQuestions, actual: Answerable<Actual>) => {

                const value  = await IsPresent.valueToCheck(actual, actor);
                const result = await IsPresent.isPresent(value, actor);

                return result
                    ? new ExpectationMet('become present', ExpectationDetails.of('isPresent'), true, actual)
                    : new ExpectationNotMet('become present', ExpectationDetails.of('isPresent'), true, actual);
            }
        );
    }
}
