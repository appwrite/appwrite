import type { Answerable, AnswersQuestions, UsesAbilities, WithAnswerableProperties } from '@serenity-js/core';
import { Question } from '@serenity-js/core';
import { d } from '@serenity-js/core/lib/io';
import type { AxiosRequestConfig } from 'axios';

/**
 * HTTP Request sent by the [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * using the [interaction](https://serenity-js.org/api/core/class/Interaction/) to [`Send`](https://serenity-js.org/api/rest/class/Send/)
 *
 * @group Models
 */
export abstract class HTTPRequest extends Question<Promise<AxiosRequestConfig>> {

    /**
     * @param [resourceUri]
     *  URL to which the request should be sent
     *
     * @param [data]
     *  Request body to be sent as part of the Put, Post or Patch request
     *
     * @param {Answerable<WithAnswerableProperties<AxiosRequestConfig>>} [config]
     *  Axios request configuration, which can be used to override the defaults
     *  provided when the [ability](https://serenity-js.org/api/core/class/Ability/) to [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/) is instantiated
     */
    protected constructor(
        protected readonly resourceUri?: Answerable<string>,
        protected readonly data?: Answerable<any>,
        protected readonly config?: Answerable<WithAnswerableProperties<AxiosRequestConfig>>,
    ) {
        super(`${ HTTPRequest.requestDescription(new.target.name) } to ${ d`${ resourceUri }` }`);
    }

    /**
     * @inheritDoc
     */
    answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<AxiosRequestConfig> {
        return Promise.all([
            this.resourceUri ? actor.answer(this.resourceUri)   : Promise.resolve(void 0),
            this.config      ? actor.answer(this.config)        : Promise.resolve({}),
            this.data        ? actor.answer(this.data)          : Promise.resolve(void 0),
        ]).
        then(([url, config, data]) =>

            Object.assign(
                {},
                { url, data },
                config,
                { method: HTTPRequest.httpMethodName(this.constructor.name) },
            ),
        ).
        then(config =>
            // eslint-disable-next-line unicorn/prefer-object-from-entries
            Object.keys(config).reduce((acc, key) => {
                if (config[key] !== null && config[key] !== undefined ) {
                    acc[key] = config[key];
                }
                return acc;
            }, {})
        );
    }

    /**
     * Determines the request method based on the name of the request class.
     * For example: GetRequest => GET, PostRequest => POST, etc.
     */
    private static httpMethodName(className: string): string {
        return className.replace(/Request/, '').toUpperCase();
    }

    /**
     * A human-readable description of the request, such as "a GET request", "an OPTIONS request", etc.
     */
    private static requestDescription(className: string): string {
        const
            vowels = [ 'A', 'E', 'I', 'O', 'U' ],
            method = HTTPRequest.httpMethodName(className);

        return `${ ~vowels.indexOf(method[0]) ? 'an' : 'a' } ${ method } request`;
    }
}
