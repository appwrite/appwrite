import type { Answerable, WithAnswerableProperties } from '@serenity-js/core';
import { Question } from '@serenity-js/core';
import type { AxiosRequestConfig } from 'axios';

import { HTTPRequest } from './HTTPRequest';

/**
 * The DELETE method requests that the origin server remove the
 * association between the target resource and its current
 * functionality.
 *
 * ## Create and then remove a resource
 *
 * ```ts
 *  import { actorCalled } from '@serenity-js/core'
 *  import { CallAnApi, DeleteRequest, LastResponse, PostRequest, Send } from '@serenity-js/rest'
 *  import { Ensure, equals, startsWith } from '@serenity-js/assertions'
 *
 *  await actorCalled('Apisitt')
 *    .whoCan(CallAnApi.at('https://api.example.org/'))
 *    .attemptsTo(
 *      // create a new test user account
 *      Send.a(PostRequest.to('/users').with({
 *          login: 'tester',
 *          password: 'P@ssword1',
 *      }),
 *      Ensure.that(LastResponse.status(), equals(201)),
 *      Ensure.that(LastResponse.header('Location'), startsWith('/users')),
 *
 *      // delete the test user account
 *      Send.a(DeleteRequest.to(LastResponse.header('Location'))),
 *      Ensure.that(LastResponse.status(), equals(200)),
 *    )
 * ```
 *
 * ## Learn more
 * - https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/DELETE
 * - https://tools.ietf.org/html/rfc7231#section-4.3.5
 *
 * @group Models
 */
export class DeleteRequest extends HTTPRequest {

    /**
     * Configures the object with a destination URI.
     *
     * When the `resourceUri` is not a fully qualified URL but a path, such as `/products/2`,
     * it gets concatenated with the URL provided to the Axios instance
     * when the [ability](https://serenity-js.org/api/core/class/Ability/) to [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/) was instantiated.
     *
     * @param resourceUri
     *  The URI where the [`Actor`](https://serenity-js.org/api/core/class/Actor/)
     *  should send the [`HTTPRequest`](https://serenity-js.org/api/rest/class/HTTPRequest/)
     */
    static to(resourceUri: Answerable<string>): DeleteRequest {
        return new DeleteRequest(resourceUri);
    }

    /**
     * Overrides the default Axios request configuration provided
     * when the [ability](https://serenity-js.org/api/core/class/Ability/) to [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/) was instantiated.
     *
     * #### Learn more
     * - [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     * - [`WithAnswerableProperties`](https://serenity-js.org/api/core/#WithAnswerableProperties)
     * - [AxiosRequestConfig](https://axios-http.com/docs/req_config)
     *
     * @param {Answerable<WithAnswerableProperties<AxiosRequestConfig>>} config
     *  Axios request configuration overrides
     */
    using(config: Answerable<WithAnswerableProperties<AxiosRequestConfig>>): DeleteRequest {
        return new DeleteRequest(this.resourceUri, undefined, Question.fromObject(config));
    }
}
