import { Expectation } from '@serenity-js/core';

import { equals } from './equals';

/**
 * Creates an [expectation](https://serenity-js.org/api/core/class/Expectation/) that is met when the actual `boolean` value
 * is `false`.
 *
 * ## Ensuring that a given value is false
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { Ensure, isFalse } from '@serenity-js/assertions'
 * import { Cookie } from '@serenity-js/web'
 *
 * await actorCalled('Ester').attemptsTo(
 *   Ensure.that(Cookie.called('example-regular-cookie').isSecure(), isFalse()),
 * )
 * ```
 *
 * @group Expectations
 */
export function isFalse(): Expectation<boolean> {
    return Expectation.to<boolean>(`equal false`).soThatActual(equals(false));
}
