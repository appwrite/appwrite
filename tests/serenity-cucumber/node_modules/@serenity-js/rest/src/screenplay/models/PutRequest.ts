import type { Answerable, WithAnswerableProperties } from '@serenity-js/core';
import { Question } from '@serenity-js/core';
import type { AxiosRequestConfig } from 'axios';

import { HTTPRequest } from './HTTPRequest';

/**
 * The PUT method requests that the state of the target resource be
 * created or replaced with the state defined by the representation
 * enclosed in the request message payload.
 *
 * PUT request should be used when you want to create
 * a new resource at a known `resourceUri` (e.g. `/books/0-688-00230-7`)
 * or replace an existing resource at such `resourceUri`.
 *
 * PUT request is [idempotent](https://developer.mozilla.org/en-US/docs/Glossary/Idempotent):
 * calling it once or several times successively has the same effect (that is no _side effect_).
 *
 * ## Create a new resource at a known location
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core';
 * import { CallAnApi, LastResponse, PutRequest, Send } from '@serenity-js/rest';
 * import { Ensure, equals } from '@serenity-js/assertions';
 *
 * await actorCalled('Apisitt')
 *   .whoCan(CallAnApi.at('https://api.example.org/'))
 *   .attemptsTo(
 *     Send.a(PutRequest.to('/books/0-688-00230-7').with({
 *       isbn: '0-688-00230-7',
 *       title: 'Zen and the Art of Motorcycle Maintenance: An Inquiry into Values',
 *       author: 'Robert M. Pirsig',
 *     })),
 *     Ensure.that(LastResponse.status(), equals(201)),
 *   )
 * ```
 *
 * ## Learn more
 * - https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/PUT
 * - https://tools.ietf.org/html/rfc7231#section-4.3.4
 *
 * @group Models
 */
export class PutRequest extends HTTPRequest {

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
    static to(resourceUri: Answerable<string>): PutRequest {
        return new PutRequest(resourceUri);
    }

    /**
     * Configures the object with a request body.
     *
     * @param data
     *  Data to be sent to the `resourceUri`
     */
    with(data: Answerable<any>): PutRequest {
        return new PutRequest(this.resourceUri, data, this.config);
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
    using(config: Answerable<WithAnswerableProperties<AxiosRequestConfig>>): PutRequest {
        return new PutRequest(this.resourceUri, this.data, Question.fromObject(config));
    }
}
