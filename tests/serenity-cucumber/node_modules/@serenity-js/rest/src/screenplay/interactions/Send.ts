import type { Answerable, AnswersQuestions, CollectsArtifacts, UsesAbilities } from '@serenity-js/core';
import { Interaction } from '@serenity-js/core';
import type { Artifact, RequestAndResponse } from '@serenity-js/core/lib/model';
import { HTTPRequestResponse, Name } from '@serenity-js/core/lib/model';
import type { AxiosRequestConfig, AxiosResponse, AxiosResponseHeaders, RawAxiosResponseHeaders } from 'axios';
import { AxiosHeaders } from 'axios';

import { CallAnApi } from '../abilities';

/**
 * Sends a [`HTTPRequest`](https://serenity-js.org/api/rest/class/HTTPRequest/) to a specified URL.
 *
 * The response to the request is made available via [`LastResponse`](https://serenity-js.org/api/rest/class/LastResponse/).
 *
 * ## Send a GET request
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { CallAnApi, GetRequest, LastResponse, Send } from '@serenity-js/rest'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * await actorCalled('Apisitt')
 *   .whoCan(CallAnApi.at('https://api.example.org/'))
 *   .attemptsTo(
 *     Send.a(GetRequest.to('/books/0-688-00230-7')),
 *     Ensure.that(LastResponse.status(), equals(200)),
 *   )
 * ```
 *
 * @group Activities
 */
export class Send extends Interaction {

    /**
     * Instantiates a new [interaction](https://serenity-js.org/api/core/class/Interaction/) to [`Send`](https://serenity-js.org/api/rest/class/Send/).
     *
     * #### Learn more
     * - [AxiosRequestConfig](https://axios-http.com/docs/req_config)
     *
     * @param request
     */
    static a(request: Answerable<AxiosRequestConfig>): Interaction {
        return new Send(request);
    }

    /**
     * @param request
     */
    protected constructor(private readonly request: Answerable<AxiosRequestConfig>) {
        super(`#actor sends ${ request.toString() }`);
    }

    /**
     * @inheritDoc
     */
    performAs(actor: UsesAbilities & CollectsArtifacts & AnswersQuestions): Promise<void> {
        const callAnApi = CallAnApi.as(actor);

        return actor.answer(this.request)
            .then(config =>
                callAnApi.request(config).then((response: AxiosResponse) => {
                    const resolvedUrl = callAnApi.resolveUrl(config);

                    actor.collect(
                        this.responseToArtifact(resolvedUrl, response),
                        this.requestToArtifactName(response.config.method, resolvedUrl),
                    );
                })
            );
    }

    private responseToArtifact(targetUrl: string, response: AxiosResponse): Artifact {
        const request: AxiosRequestConfig = response.config;

        const axiosRequestHeaders = request.headers;
        const requestHeaders: Record<string, string | number | boolean> = AxiosHeaders.from(axiosRequestHeaders).toJSON(true) as Record<string, string | number | boolean>;

        const axiosResponseHeaders: RawAxiosResponseHeaders | AxiosResponseHeaders = response.headers;
        const responseHeaders = AxiosHeaders.from(axiosResponseHeaders).toJSON(false) as RawAxiosResponseHeaders;

        const requestAndResponse: RequestAndResponse = {
            request: {
                method:     request.method,
                url:        targetUrl,
                headers:    requestHeaders,
                data:       request.data,
            },
            response: {
                status:     response.status,
                headers:    responseHeaders as unknown as Record<string, string> & { 'set-cookie'?: string[] },
                data:       response.data,
            },
        };

        return HTTPRequestResponse.fromJSON(requestAndResponse);
    }

    private requestToArtifactName(method: string, url: string) {
        return new Name(`${ method.toUpperCase() } ${ url }`);
    }
}
