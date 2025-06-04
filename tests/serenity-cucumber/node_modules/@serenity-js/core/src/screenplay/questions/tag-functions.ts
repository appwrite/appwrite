import { asyncMap, ValueInspector } from '../../io';
import type { UsesAbilities } from '../abilities';
import type { Answerable } from '../Answerable';
import type { MetaQuestionAdapter, QuestionAdapter } from '../Question';
import { Question } from '../Question';
import type { AnswersQuestions } from './AnswersQuestions';
import { Describable } from './Describable';
import type { DescriptionFormattingOptions } from './DescriptionFormattingOptions';
import type { MetaQuestion } from './MetaQuestion';

/**
 * Creates a single-line description of an [`Activity`](https://serenity-js.org/api/core/class/Activity/) by transforming
 * a [template literal](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Template_literals#Tagged_templates),
 * parameterised with [primitive data types](https://developer.mozilla.org/en-US/docs/Glossary/Primitive),
 * [complex data structures](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Data_structures#objects),
 * or any other [answerables](https://serenity-js.org/api/core/#Answerable),
 * into a [`QuestionAdapter<string>`](https://serenity-js.org/api/core/#QuestionAdapter)
 * that can be used with [`Task.where`](https://serenity-js.org/api/core/class/Task/#where) and [`Interaction.where`](https://serenity-js.org/api/core/class/Interaction/#where) methods.
 *
 * ```ts
 * const dial = (phoneNumber: Answerable<string>) =>
 *  Task.where(the `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial('(555) 123-4567'),
 *   // reported as: Alice dials "(555) 123-4567"
 * )
 * ```
 *
 * ## Trimming the output
 *
 * Use [`DescriptionFormattingOptions`](http://serenity-js.org/api/core/interface/DescriptionFormattingOptions/)
 * to trim the descriptions of template parameters.
 * By default, the output is displayed in full.
 *
 * ```ts
 * import { actorCalled, Task, the } from '@serenity-js/core'
 *
 * const dial = (phoneNumber: Answerable<string>) =>
 *  Task.where(dial({ maxLength: 10 }) `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial('(555) 123-4567'),
 *   // reported as: Alice dials "(555) 123-...'
 * )
 * ```
 *
 * ## Using with Questions
 *
 * When `the` is parameterised with [questions](https://serenity-js.org/api/core/class/Question/),
 * it retrieves their description by calling [`Question.describedBy`](https://serenity-js.org/api/core/class/Question/#describedBy)
 * in the context of the [`Actor`](https://serenity-js.org/api/core/class/Actor/) performing the [`Activity`](https://serenity-js.org/api/core/class/Activity/).
 * 
 * ```ts
 * import { actorCalled, Question, Task, the } from '@serenity-js/core'
 *
 * const phoneNumber = (areaCode: string, centralOfficeCode: string, lineNumber: string) =>
 *  Question.about('phone number', actor => {
 *     return `(${ this.areaCode }) ${ this.centralOfficeCode }-${ this.lineNumber }`
 *   })
 * 
 * const dial = (phoneNumber: Answerable<string>) =>
 *  Task.where(dial({ maxLength: 10 }) `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial(phoneNumber('555', '123', '4567'),
 *   // reported as: Alice dials phone number
 * )
 * ```
 * 
 * If you'd like the question to be described using its formatted value instead of its description, use [`Question.formattedValue`](https://serenity-js.org/api/core/class/Question/#formattedValue).
 *
 * ```ts
 * import { actorCalled, Question, Task, the } from '@serenity-js/core'
 *
 * const phoneNumber = (areaCode: string, centralOfficeCode: string, lineNumber: string) =>
 *   Question.about('phone number', actor => {
 *     return `(${ this.areaCode }) ${ this.centralOfficeCode }-${ this.lineNumber }`
 *   }).describedAs(Question.formattedValue())
 *
 * const dial = (phoneNumber: Answerable<string>) =>
 *  Task.where(dial({ maxLength: 10 }) `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial(phoneNumber('555', '123', '4567'),
 *   // reported as: Alice dials "(555) 123-4567"
 * )
 * ```
 *
 * ## Using with objects with a custom `toString` method
 *
 * When `the` is parameterised with objects that have
 * a custom [`toString()`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Object/toString) method,
 * or [answerables](https://serenity-js.org/api/core/#Answerable) resolving to such objects, the `toString()` method is called to produce the resulting description.
 *
 * ```ts
 * import { actorCalled, description, Task } from '@serenity-js/core'
 *
 * class PhoneNumber {
 *   constructor(
 *     private readonly areaCode: string,
 *     private readonly centralOfficeCode: string,
 *     private readonly lineNumber: string,
 *   ) {}
 *
 *   toString() {
 *     return `(${ this.areaCode }) ${ this.centralOfficeCode }-${ this.lineNumber }`
 *   }
 * }
 *
 * const dial = (phoneNumber: Answerable<PhoneNumber>) =>
 *  Task.where(description `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial(new PhoneNumber('555', '123', '4567')),
 *   // reported as: Alice dials (555) 123-4567
 * )
 * ```
 *
 * ## Using with objects without a custom `toString` method
 *
 * When `the` is parameterised with complex objects that don't have a custom `toString()` method,
 * or [`Answerable`](https://serenity-js.org/api/core/#Answerable)s resolving to such objects,
 * the resulting description will contain a JSON-like string representation of the object.
 *
 * ```ts
 * import { actorCalled, description, Task } from '@serenity-js/core'
 *
 * interface PhoneNumber {
 *   areaCode: string;
 *   centralOfficeCode: string;
 *   lineNumber: string;
 * }
 *
 * const dial = (phoneNumber: Answerable<PhoneNumber>) =>
 *  Task.where(the `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial({ areaCode: '555', centralOfficeCode: '123', lineNumber: '4567' }),
 *   // reported as: Alice dials { areaCode: "555", centralOfficeCode: "123", lineNumber: "4567" }
 * )
 * ```
 *
 * ## Using with masked values
 *
 * When `the` is parameterised with [masked values](https://serenity-js.org/api/core/class/Masked/),
 * the resulting description will contain a masked representation of the values.
 *
 * ```ts
 * import { actorCalled, description, Task } from '@serenity-js/core'
 *
 * const dial = (phoneNumber: Answerable<string>) =>
 *  Task.where(description `#actor dials ${ phoneNumber }`, /* *\/)
 *
 * await actorCalled('Alice').attemptsTo(
 *   dial(Masked.valueOf('(555) 123-4567')),
 *   // reported as: Alice dials [a masked value]
 * )
 * ```
 *
 * ## Learn more
 *
 * - [`Answerable`](https://serenity-js.org/api/core/#Answerable)
 * - [`Question`](https://serenity-js.org/api/core/class/Question/)
 * - [`Question.describedAs`](https://serenity-js.org/api/core/class/Question/#describedAs)
 * - [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter)
 * - [`Masked`](https://serenity-js.org/api/core/class/Masked/)
 *
 * @group Questions
 */
export function the(options: DescriptionFormattingOptions): <Supported_Context_Type>(templates: TemplateStringsArray, ...placeholders: Array<MetaQuestion<Supported_Context_Type, any> | any>) => MetaQuestionAdapter<Supported_Context_Type, string>
export function the<Supported_Context_Type>(templates: TemplateStringsArray, ...parameters: Array<MetaQuestion<Supported_Context_Type, any> | any>): MetaQuestionAdapter<Supported_Context_Type, string>
export function the(...args: any[]): any {
    if (ValueInspector.isPlainObject(args[0])) {
        const descriptionFormattingOptions = args[0] as DescriptionFormattingOptions;

        return (templates: TemplateStringsArray, ...parameters: Array<any>) =>
            templateToQuestion(templates, parameters, createParameterToDescriptionMapper(descriptionFormattingOptions), createParameterValueToDescriptionMapper(descriptionFormattingOptions));
    }

    return templateToQuestion(args[0], args.slice(1), createParameterToDescriptionMapper(), createParameterValueToDescriptionMapper());
}

/**
 * A Serenity/JS Screenplay Pattern-flavour
 * of a [tagged template literal](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Template_literals#Tagged_templates),
 * `q` is a tag function capable of resolving any `Answerable<string>` or `Answerable<number>`
 * you parametrise it with, and returning a `QuestionAdapter<string>`.
 *
 * Use `q` to concatenate `string` and `number` values returned from synchronous an asynchronous sources.
 *
 * ## Interpolating questions
 *
 * ```ts
 * import { q, actorCalled } from '@serenity-js/core'
 * import { Send, DeleteRequest } from '@serenity-js/rest'
 * import { Text } from '@serenity-js/web'
 *
 * await actorCalled('Alice').attemptsTo(
 *   Send.a(DeleteRequest.to(
 *     q `/articles/${ Text.of(Article.id()) }`
 *   ))
 * )
 * ```
 *
 * ## Using a custom description
 *
 * ```ts
 * import { q, actorCalled } from '@serenity-js/core'
 * import { Send, DeleteRequest } from '@serenity-js/rest'
 *
 * await actorCalled('Alice').attemptsTo(
 *   Send.a(DeleteRequest.to(
 *     q `/articles/${ Text.of(Article.id()) }`.describedAs('/articles/:id')
 *   ))
 * )
 * ```
 *
 * ## Transforming the interpolated string
 *
 * The mechanism presented below relies on [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter).
 *
 * ```ts
 * import { q, actorCalled } from '@serenity-js/core'
 * import { Send, DeleteRequest } from '@serenity-js/rest'
 *
 * await actorCalled('Alice').attemptsTo(
 *   Send.a(DeleteRequest.to(
 *     q `/articles/${ Text.of(Article.id()) }`.toLocaleLowerCase()
 *   ))
 * )
 * ```
 *
 * ## Learn more
 *
 * - [`Answerable`](https://serenity-js.org/api/core/#Answerable)
 * - [`Question`](https://serenity-js.org/api/core/class/Question/)
 * - [`Question.describedAs`](https://serenity-js.org/api/core/class/Question/#describedAs)
 * - [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter)
 *
 * @group Questions
 *
 * @param templates
 * @param parameters
 */
export function q(templates: TemplateStringsArray, ...parameters: Array<Answerable<string | number>>): QuestionAdapter<string> {
    return templateToQuestion(
        templates,
        parameters,
        (parameter: Answerable<string | number>) => {
            // return static string and number parameter values as is
            if (typeof parameter === 'string' || typeof parameter === 'number') {
                return String(parameter);
            }
            // for Questions, Promises and other Answerables, return their description
            return `{${ createParameterToDescriptionMapper()(parameter) }}`
        },
        createParameterValueMapper()
    );
}

function createParameterToDescriptionMapper(options?: DescriptionFormattingOptions) {
    return (parameter: any) =>
        parameter === undefined
            ? 'undefined'
            : Question.formattedValue(options).of(parameter).toString();
}

function createParameterValueToDescriptionMapper(options?: DescriptionFormattingOptions) {
    return async (actor: AnswersQuestions & UsesAbilities & { name: string }, parameter: any) =>
        parameter instanceof Describable
            ? parameter.describedBy(actor)
            : actor.answer(Question.formattedValue(options).of(parameter))
}

function createParameterValueMapper() {
    return async (actor: AnswersQuestions & UsesAbilities & { name: string }, parameter: Answerable<string | number>) =>
        actor.answer(parameter)
}

function templateToQuestion(
    templates: TemplateStringsArray,
    parameters: Array<any>,
    descriptionMapper: (parameter: any) => string,
    valueMapper: (actor: AnswersQuestions & UsesAbilities & { name: string }, parameter: any) => Promise<any> | any,
) {
    const description = interpolate(templates, parameters.map(parameter => descriptionMapper(parameter)));

    return Question.about<string, any>(description,
        async (actor: AnswersQuestions & UsesAbilities & { name: string }) => {
            const descriptions = await asyncMap(parameters, parameter => valueMapper(actor, parameter));

            return interpolate(templates, descriptions);
        },
        (context: any) =>
            templateToQuestion(
                templates,
                parameters.map(parameter =>
                    Question.isAMetaQuestion(parameter)
                        ? parameter.of(context)
                        : parameter
                ),
                descriptionMapper,
                valueMapper,
            )
    );
}

function interpolate(templates: TemplateStringsArray, parameters: Array<any>): string {
    return templates.flatMap((template, i) =>
        i < parameters.length
            ? [ template, parameters[i] ]
            : [ template ],
    ).join('');
}
