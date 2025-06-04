import type { Answerable, WithAnswerableProperties } from '@serenity-js/core';
import { Question } from '@serenity-js/core';
import type { AxiosRequestConfig } from 'axios';

import { HTTPRequest } from './HTTPRequest';

/**
 * The PATCH method requests that a set of changes described in the
 * request entity be applied to the resource identified by the `resourceUri`.
 *
 * ## Add new resource to a collection
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { CallAnApi, LastResponse, PatchRequest, Send } from '@serenity-js/rest'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * await actorCalled('Apisitt')
 *   .whoCan(CallAnApi.at('https://api.example.org/'))
 *   .attemptsTo(
 *     Send.a(PatchRequest.to('/books/0-688-00230-7').with({
 *       lastReadOn: '2016-06-16',
 *     })),
 *     Ensure.that(LastResponse.status(), equals(204)),
 *   )
 * ```
 *
 * ## Learn more
 * - https://developer.mozilla.org/en-US/docs/Web/HTTP/Methods/PATCH
 * - https://tools.ietf.org/html/rfc5789
 *
 * @group Models
 */
export class PatchRequest extends HTTPRequest {

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
    static to(resourceUri: Answerable<string>): PatchRequest {
        return new PatchRequest(resourceUri);
    }

    /**
     * Configures the object with a request body.
     *
     * @param data
     *  Data to be sent to the `resourceUri`
     */
    with(data: Answerable<any>): PatchRequest {
        return new PatchRequest(this.resourceUri, data, this.config);
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
    using(config: Answerable<WithAnswerableProperties<AxiosRequestConfig>>): PatchRequest {
        return new PatchRequest(this.resourceUri, this.data, Question.fromObject(config));
    }
}
