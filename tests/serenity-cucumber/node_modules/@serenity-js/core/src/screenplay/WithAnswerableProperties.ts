import type { Answerable } from './Answerable';
import type { Question } from './Question';

/**
 * Describes a plain JavaScript object with [`Answerable`](https://serenity-js.org/api/core/#Answerable) properties.
 * Typically, used in conjunction with [`RecursivelyAnswered`](https://serenity-js.org/api/core/#RecursivelyAnswered) and [`Question.fromObject`](https://serenity-js.org/api/core/class/Question/#fromObject).
 *
 * ```ts
 * import {
 *   actorCalled, notes, q, Question, QuestionAdapter, WithAnswerableProperties
 * } from '@serenity-js/core';
 *
 * interface RequestConfiguration {
 *   headers: Record<string, string>;
 * }
 *
 * const requestConfiguration: WithAnswerableProperties<RequestConfiguration> = {
 *   headers: {
 *     Authorization: q`Bearer ${ notes().get('authDetails').token }`
 *   }
 * }
 *
 * const question: QuestionAdapter<RequestConfiguration> =
 *     Question.fromObject<RequestConfiguration>(requestConfiguration)
 *
 * const answer: RequestConfiguration = await actorCalled('Annie').answer(question);
 * ```
 *
 * @group Questions
 */
export type WithAnswerableProperties<T> =
    T extends null | undefined ? T :
        T extends Question<Promise<infer A>> | Question<infer A> | Promise<infer A> ? Answerable<A> :
            T extends object ? { [K in keyof T]: WithAnswerableProperties<T[K]> } :
                Answerable<T>
;
