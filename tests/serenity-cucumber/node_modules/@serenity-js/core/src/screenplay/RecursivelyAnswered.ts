import type { Question } from './Question';

/**
 * Describes a recursively resolved plain JavaScript object with [answerable properties](https://serenity-js.org/api/core/#WithAnswerableProperties).
 *
 * Typically, used in conjunction with [`Question.fromObject`](https://serenity-js.org/api/core/class/Question/#fromObject).
 *
 * ## Using `RecursivelyAnswered`
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
 *   Question.fromObject<RequestConfiguration>(requestConfiguration)
 *
 * const answer = await actorCalled('Annie').answer(question);
 *
 * const a1: RequestConfiguration = answer;
 * const a2: RecursivelyAnswered<WithAnswerableProperties<RequestConfiguration>> = answer;
 *
 * // RequestConfiguration === RecursivelyAnswered<WithAnswerableProperties<RequestConfiguration>>
 * ```
 *
 * @group Questions
 */
export type RecursivelyAnswered<T> =
    T extends null | undefined ? T :          // special case for `null | undefined` when not in `--strictNullChecks` mode
        T extends Question<Promise<infer A>> | Question<infer A> | Promise<infer A> ? RecursivelyAnswered<Awaited<A>> :
            T extends object ? { [K in keyof T]: RecursivelyAnswered<T[K]> } :
                T
;
