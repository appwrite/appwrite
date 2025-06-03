import { Expectation } from '@serenity-js/core';

import { equals } from './equals';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual `boolean` value
 * is `true`.
 *
 * ## Ensuring that a given value is true
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, isTrue } from '@serenity-js/assertions'
 * import { Cookie } from '@serenity-js/web'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(Cookie.called('example-secure-cookie').isSecure(), isTrue()),
 * )
 * ```
 *
 * @group Expectations
 */
export function isTrue(): Expectation<boolean> {
    return Expectation.to<boolean>(`equal true`).soThatActual(equals(true));
}
