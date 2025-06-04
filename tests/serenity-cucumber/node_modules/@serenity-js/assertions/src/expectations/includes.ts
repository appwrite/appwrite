import { Expectation } from '@serenity-js/core';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual `string` value
 * includes a substring of `expected`.
 *
 * ## Ensuring that a given string includes the expected substring
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, includes } from '@serenity-js/assertions'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that('Hello World!', includes('World')),
 * )
 * ```
 *
 * ## Ensuring that page URL includes the expected substring
 *
 * [`Page.current().url()`](https://serenity-js.org/api/web/class/Page/#url) returns a [`QuestionAdapter<URL>`](https://serenity-js.org/api/core/#QuestionAdapter)
 * a proxy object around the standard Node.js [URL](https://nodejs.org/api/url.html) class,
 * offering access to `string` properties such as [`hostname`](https://nodejs.org/api/url.html#urlobjecthostname),
 * [`pathname`](https://nodejs.org/api/url.html#urlobjectpathname), and so on.
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, includes } from '@serenity-js/assertions'
 * import { Navigate, Page } from '@serenity-js/web'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Navigate.to('https://serenity-js.org/handbook'),
 *   Ensure.that(Page.current().url().hostname, includes('serenity-js')),
 *   Ensure.that(Page.current().url().pathname, includes('book')),
 * )
 * ```
 *
 * @param expected
 *
 * @group Expectations
 */
export const includes = Expectation.define(
    'includes', 'include',
    (actual: string, expected: string) =>
        actual.includes(expected)
);
