import { isRecord, significantFieldsOf } from 'tiny-types/lib/objects';
import * as util from 'util'; // eslint-disable-line unicorn/import-style

import { LogicError } from '../errors';
import type { FileSystemLocation } from '../io';
import { asyncMap, f, inspectedObject, ValueInspector } from '../io';
import type { UsesAbilities } from './abilities';
import type { Answerable } from './Answerable';
import { Interaction } from './Interaction';
import type { Optional } from './Optional';
import type { AnswersQuestions } from './questions/AnswersQuestions';
import { Describable } from './questions/Describable';
import type { DescriptionFormattingOptions } from './questions/DescriptionFormattingOptions';
import type { MetaQuestion } from './questions/MetaQuestion';
import { the } from './questions/tag-functions';
import { Unanswered } from './questions/Unanswered';
import type { RecursivelyAnswered } from './RecursivelyAnswered';
import type { WithAnswerableProperties } from './WithAnswerableProperties';

/**
 * **Questions** describe how [actors](https://serenity-js.org/api/core/class/Actor/) should query the system under test or the test environment to retrieve some information.
 *
 * Questions are the core building block of the [Screenplay Pattern](https://serenity-js.org/handbook/design/screenplay-pattern),
 * along with [actors](https://serenity-js.org/api/core/class/Actor/), [abilities](https://serenity-js.org/api/core/class/Ability/),
 * [interactions](https://serenity-js.org/api/core/class/Interaction/),
 * and [tasks](https://serenity-js.org/api/core/class/Task/).
 *
 * ![Screenplay Pattern](https://serenity-js.org/images/design/serenity-js-screenplay-pattern.png)
 *
 * Learn more about:
 * - [`Actor`](https://serenity-js.org/api/core/class/Actor/)
 * - [`Ability`](https://serenity-js.org/api/core/class/Ability/)
 * - [`Interaction`](https://serenity-js.org/api/core/class/Interaction/)
 * - [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter)
 *
 * ## Implementing a basic custom Question
 *
 * ```ts
 *  import { actorCalled, AnswersQuestions, UsesAbilities, Question } from '@serenity-js/core'
 *  import { Ensure, equals } from '@serenity-js/assertions'
 *
 *  const LastItemOf = <T>(list: T[]): Question<T> =>
 *    Question.about('last item from the list', (actor: AnswersQuestions & UsesAbilities) => {
 *      return list[list.length - 1]
 *    });
 *
 *  await actorCalled('Quentin').attemptsTo(
 *    Ensure.that(LastItemFrom([1,2,3]), equals(3)),
 *  )
 * ```
 *
 * ## Implementing a Question that uses an Ability
 *
 * Just like the [interactions](https://serenity-js.org/api/core/class/Interaction/), a [`Question`](https://serenity-js.org/api/core/class/Question/)
 * also can use [actor's](https://serenity-js.org/api/core/class/Actor/) [abilities](https://serenity-js.org/api/core/class/Ability/).
 *
 * Here, we use the ability to [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/) to retrieve a property of
 * an HTTP response.
 *
 * ```ts
 *  import { AnswersQuestions, UsesAbilities, Question } from '@serenity-js/core'
 *  import { CallAnApi } from '@serenity-js/rest'
 *
 *  const TextOfLastResponseStatus = () =>
 *    Question.about<number>(`the text of the last response status`, actor => {
 *      return CallAnApi.as(actor).mapLastResponse(response => response.statusText)
 *    })
 * ```
 *
 * #### Learn more
 * - [`CallAnApi`](https://serenity-js.org/api/rest/class/CallAnApi/)
 * - [`LastResponse`](https://serenity-js.org/api/rest/class/LastResponse/)
 *
 * ## Mapping answers to other questions
 *
 * Apart from retrieving information, [questions](https://serenity-js.org/api/core/class/Question/) can be used to transform information retrieved by other questions.
 *
 * Here, we use the factory method [`Question.about`](https://serenity-js.org/api/core/class/Question/#about) to produce a question that makes the received [actor](https://serenity-js.org/api/core/class/Actor/)
 * answer [`LastResponse.status`](https://serenity-js.org/api/rest/class/LastResponse/#status) and then compare it against some expected value.
 *
 * ```ts
 * import { actorCalled, AnswersQuestions, UsesAbilities, Question } from '@serenity-js/core'
 * import { CallAnApi, LastResponse } from '@serenity-js/rest'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * const RequestWasSuccessful = () =>
 *   Question.about<number>(`the text of the last response status`, async actor => {
 *     const status = await actor.answer(LastResponse.status());
 *
 *     return status === 200;
 *   })
 *
 * await actorCalled('Quentin')
 *   .whoCan(CallAnApi.at('https://api.example.org/'));
 *   .attemptsTo(
 *     Send.a(GetRequest.to('/books/0-688-00230-7')),
 *     Ensure.that(RequestWasSuccessful(), isTrue()),
 *   )
 * ```
 *
 * Note that the above example is for demonstration purposes only, Serenity/JS provides an easier way to
 * verify the response status of the [`LastResponse`](https://serenity-js.org/api/rest/class/LastResponse/):
 *
 * ```ts
 * import { actorCalled } from '@serenity-js/core'
 * import { CallAnApi, LastResponse } from '@serenity-js/rest'
 * import { Ensure, equals } from '@serenity-js/assertions'
 *
 * await actorCalled('Quentin')
 *   .whoCan(CallAnApi.at('https://api.example.org/'));
 *   .attemptsTo(
 *     Send.a(GetRequest.to('/books/0-688-00230-7')),
 *     Ensure.that(LastResponse.status(), equals(200)),
 *   )
 * ```
 *
 * @group Screenplay Pattern
 */
export abstract class Question<T> extends Describable {

    /**
     * Factory method that simplifies the process of defining custom questions.
     *
     * #### Defining a custom question
     *
     * ```ts
     * import { Question } from '@serenity-js/core'
     *
     * const EnvVariable = (name: string) =>
     *   Question.about(`the ${ name } env variable`, actor => process.env[name])
     * ```
     *
     * @param description
     * @param body
     * @param [metaQuestionBody]
     */
    static about<Answer_Type, Supported_Context_Type>(
        description: Answerable<string>,
        body: (actor: AnswersQuestions & UsesAbilities) => Promise<Answer_Type> | Answer_Type,
        metaQuestionBody: (answerable: Answerable<Supported_Context_Type>) => Question<Promise<Answer_Type>> | Question<Answer_Type>,
    ): MetaQuestionAdapter<Supported_Context_Type, Awaited<Answer_Type>>

    static about<Answer_Type>(
        description: Answerable<string>,
        body: (actor: AnswersQuestions & UsesAbilities) => Promise<Answer_Type> | Answer_Type
    ): QuestionAdapter<Awaited<Answer_Type>>

    static about<Answer_Type, Supported_Context_Type extends Answerable<any>>(
        description: Answerable<string>,
        body: (actor: AnswersQuestions & UsesAbilities) => Promise<Answer_Type> | Answer_Type,
        metaQuestionBody?: (answerable: Supported_Context_Type) => QuestionAdapter<Answer_Type>,
    ): any
    {
        const statement = typeof metaQuestionBody === 'function'
            ? new MetaQuestionStatement(description, body, metaQuestionBody)
            : new QuestionStatement(description, body);

        return Question.createAdapter(statement);
    }

    /**
     * Generates a [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter) that recursively resolves
     * any [`Answerable`](https://serenity-js.org/api/core/#Answerable) fields of the provided object,
     * including [`Answerable`](https://serenity-js.org/api/core/#Answerable) fields
     * of [nested objects](https://serenity-js.org/api/core/#WithAnswerableProperties).
     *
     * Optionally, the method accepts `overrides` to be shallow-merged with the fields of the original `source`,
     * producing a new merged object.
     *
     * Overrides are applied from left to right, with subsequent objects overwriting property assignments of the previous ones.
     *
     * #### Resolving an object recursively using `Question.fromObject`
     *
     * ```ts
     * import { actorCalled, Question } from '@serenity-js/core'
     * import { Send, PostRequest } from '@serenity-js/rest'
     * import { By, Text, PageElement } from '@serenity-js/web'
     *
     * await actorCalled('Daisy')
     *   .whoCan(CallAnApi.at('https://api.example.org'))
     *   .attemptsTo(
     *     Send.a(
     *       PostRequest.to('/products/2')
     *         .with(
     *           Question.fromObject({
     *             name: Text.of(PageElement.located(By.css('.name'))),
     *           })
     *         )
     *       )
     *   );
     * ```
     *
     * #### Merging objects using `Question.fromObject`
     *
     * ```ts
     *  import { actorCalled, Question } from '@serenity-js/core'
     *  import { Send, PostRequest } from '@serenity-js/rest'
     *  import { By, Text, PageElement } from '@serenity-js/web'
     *
     *  await actorCalled('Daisy')
     *    .whoCan(CallAnApi.at('https://api.example.org'))
     *    .attemptsTo(
     *      Send.a(
     *        PostRequest.to('/products/2')
     *          .with(
     *            Question.fromObject({
     *              name: Text.of(PageElement.located(By.css('.name'))),
     *              quantity: undefined,
     *            }, {
     *              quantity: 2,
     *            })
     *          )
     *        )
     *    );
     * ```
     *
     * #### Learn more
     * - [`WithAnswerableProperties`](https://serenity-js.org/api/core/#WithAnswerableProperties)
     * - [`RecursivelyAnswered`](https://serenity-js.org/api/core/#RecursivelyAnswered)
     * - [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     *
     * @param source
     * @param overrides
     */
    static fromObject<Source_Type extends object>(
        source: Answerable<WithAnswerableProperties<Source_Type>>,
        ...overrides: Array<Answerable<Partial<WithAnswerableProperties<Source_Type>>>>
    ): QuestionAdapter<RecursivelyAnswered<Source_Type>> {
        return Question.about<RecursivelyAnswered<Source_Type>>('value', async actor => {
            if (source === null || source === undefined) {
                return source;
            }

            const sources: Array<Partial<RecursivelyAnswered<Source_Type>>> = [];

            for (const [ i, currentSource ] of [ source, ...overrides ].entries()) {
                sources.push(
                    await recursivelyAnswer(actor, currentSource as any, `argument ${ i }`) as unknown as Partial<RecursivelyAnswered<Source_Type>>,
                );
            }

            return Object.assign({}, ...sources);
        });
    }

    /**
     * Generates a [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter) that resolves
     * any [`Answerable`](https://serenity-js.org/api/core/#Answerable) elements of the provided array.
     */
    static fromArray<Source_Type>(source: Array<Answerable<Source_Type>>, options?: DescriptionFormattingOptions): QuestionAdapter<Source_Type[]> {
        const formatter = new ValueFormatter(ValueFormatter.defaultOptions);

        const description = source.length === 0
            ? '[ ]'
            : Question.about(formatter.format(source), async (actor: AnswersQuestions & UsesAbilities & { name: string }) => {
                const descriptions = await asyncMap(source, item =>
                    item instanceof Describable
                        ? item.describedBy(actor)
                        : Question.formattedValue(options).of(item).answeredBy(actor)
                );

                return `[ ${ descriptions.join(', ') } ]`;
            });

        return Question.about<Source_Type[]>(description, async actor => {
            return await asyncMap<Answerable<Source_Type>, Source_Type>(source, item => actor.answer(item));
        });
    }

    /**
     * Checks if the value is a [`Question`](https://serenity-js.org/api/core/class/Question/).
     *
     * @param maybeQuestion
     *  The value to check
     */
    static isAQuestion<T>(maybeQuestion: unknown): maybeQuestion is Question<T> {
        return !! maybeQuestion
            && typeof (maybeQuestion as any).answeredBy === 'function';
    }

    /**
     * Checks if the value is a [`MetaQuestion`](https://serenity-js.org/api/core/interface/MetaQuestion/).
     *
     * @param maybeMetaQuestion
     *  The value to check
     */
    static isAMetaQuestion<CT, RQT extends Question<unknown>>(maybeMetaQuestion: unknown): maybeMetaQuestion is MetaQuestion<CT, RQT> {
        return !! maybeMetaQuestion
            && typeof maybeMetaQuestion['of'] === 'function'
            && maybeMetaQuestion['of'].length === 1;            // arity of 1
    }

    /**
     * Creates a [`MetaQuestion`](https://serenity-js.org/api/core/interface/MetaQuestion/) that can be composed with any [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     * to produce a single-line description of its value.
     *
     * ```ts
     * import { actorCalled, Question } from '@serenity-js/core'
     * import { Ensure, equals } from '@serenity-js/assertions'
     *
     * const accountDetails = () =>
     *   Question.about('account details', actor => ({ name: 'Alice', age: 28 }))
     *
     * await actorCalled('Alice').attemptsTo(
     *   Ensure.that(
     *     Question.formattedValue().of(accountDetails()),
     *     equals('{ name: "Alice", age: 28 }'),
     *   ),
     * )
     * ```
     *
     * @param options
     */
    static formattedValue(options?: DescriptionFormattingOptions): MetaQuestion<any, Question<Promise<string>>> {
        return MetaQuestionAboutFormattedValue.using(options);
    }

    /**
     * Creates a [`MetaQuestion`](https://serenity-js.org/api/core/interface/MetaQuestion/) that can be composed with any [`Answerable`](https://serenity-js.org/api/core/#Answerable)
     * to return its value when the answerable is a [`Question`](https://serenity-js.org/api/core/class/Question/),
     * or the answerable itself otherwise.
     *
     * The description of the resulting question is produced by calling [`Question.describedBy`](https://serenity-js.org/api/core/class/Question/#describedBy) on the
     * provided answerable.
     *
     * ```ts
     * import { actorCalled, Question } from '@serenity-js/core'
     * import { Ensure, equals } from '@serenity-js/assertions'
     *
     * const accountDetails = () =>
     *   Question.about('account details', actor => ({ name: 'Alice', age: 28 }))
     *
     * await actorCalled('Alice').attemptsTo(
     *   Ensure.that(
     *     Question.description().of(accountDetails()),
     *     equals('account details'),
     *   ),
     *   Ensure.that(
     *     Question.value().of(accountDetails()),
     *     equals({ name: 'Alice', age: 28 }),
     *   ),
     * )
     * ```
     */
    static value<Answer_Type>(): MetaQuestion<Answer_Type, Question<Promise<Answer_Type>>> {
        return new MetaQuestionAboutValue<Answer_Type>();
    }

    protected static createAdapter<AT>(statement: Question<AT>): QuestionAdapter<Awaited<AT>> {
        function getStatement() {
            return statement;
        }

        if (typeof statement[util.inspect.custom] === 'function') {
            Object.defineProperty(
                // statement must be a function because Proxy apply trap works only with functions
                getStatement,
                util.inspect.custom, {
                    value: statement[util.inspect.custom].bind(statement),
                    writable: false,
                })
        }

        return new Proxy<() => Question<AT>, QuestionStatement<AT>>(getStatement, {

            get(currentStatement: () => Question<AT>, key: string | symbol, receiver: any) {
                const target = currentStatement();

                if (key === util.inspect.custom) {
                    return target[util.inspect.custom].bind(target);
                }

                if (key === Symbol.toPrimitive) {
                    return (_hint: 'number' | 'string' | 'default') => {
                        return target.toString();
                    };
                }

                if (key in target) {

                    const field = Reflect.get(target, key);

                    const isFunction = typeof field == 'function'
                    const mustAllowProxyChaining = isFunction
                        && target instanceof QuestionStatement
                        && key === 'describedAs';   // `describedAs` returns `this`, which must be bound to proxy itself to allow proxy chaining

                    if (mustAllowProxyChaining) {
                        // see https://javascript.info/proxy#proxy-limitations
                        return field.bind(receiver)
                    }

                    return isFunction
                        ? field.bind(target)
                        : field;
                }

                if (key === 'then') {
                    return;
                }

                return Question.about(Question.staticFieldDescription(target, key), async (actor: AnswersQuestions & UsesAbilities) => {
                    const answer = await actor.answer(target as Answerable<AT>);

                    if (!isDefined(answer)) {
                        return undefined;       // eslint-disable-line unicorn/no-useless-undefined
                    }

                    const field = answer[key];

                    return typeof field === 'function'
                        ? field.bind(answer)
                        : field;
                }).describedAs(Question.formattedValue());
            },

            set(currentStatement: () => Question<AT>, key: string | symbol, value: any, receiver: any): boolean {
                const target = currentStatement();

                return Reflect.set(target, key, value);
            },

            apply(currentStatement: () => Question<AT>, _thisArgument: any, parameters: unknown[]): QuestionAdapter<AT> {
                const target = currentStatement();

                return Question.about(Question.methodDescription(target, parameters), async actor => {
                    const params = [] as any;
                    for (const parameter of parameters) {
                        const answered = await actor.answer(parameter);
                        params.push(answered);
                    }

                    const field = await actor.answer(target);

                    return typeof field === 'function'
                        ? field(...params)
                        : field;
                });
            },

            getPrototypeOf(currentStatement: () => Question<AT>): object | null {
                return Reflect.getPrototypeOf(currentStatement());
            },
        }) as any;
    }

    private static staticFieldDescription<AT>(target: Question<AT>, key: string | symbol): string {

        // "of" is characteristic of Serenity/JS MetaQuestion
        if (key === 'of') {
            return `${ target } ${ key }`;
        }

        const originalSubject = f`${ target }`;

        const fieldDescription = (typeof key === 'number' || /^\d+$/.test(String(key)))
            ? `[${ String(key) }]`  // array index
            : `.${ String(key) }`;  // field/method name

        return `${ originalSubject }${ fieldDescription }`;
    }

    private static methodDescription<AT>(target: Question<AT>, parameters: unknown[]): string {

        const targetDescription = target.toString();

        // this is a Serenity/JS MetaQuestion, of(singleParameter)
        if (targetDescription.endsWith(' of') && parameters.length === 1) {
            return `${ targetDescription } ${ parameters[0] }`;
        }

        const parameterDescriptions = [
            '(', parameters.map(p => f`${ p }`).join(', '), ')',
        ].join('');

        return `${ targetDescription }${ parameterDescriptions }`;
    }

    /**
     * Instructs the provided [`Actor`](https://serenity-js.org/api/core/class/Actor/) to use their [abilities](https://serenity-js.org/api/core/class/Ability/)
     * to answer this question.
     */
    abstract answeredBy(actor: AnswersQuestions & UsesAbilities): T;

    /**
     * Changes the description of this object, as returned by [`Describable.describedBy`](https://serenity-js.org/api/core/class/Describable/#describedBy)
     * and [`Describable.toString`](https://serenity-js.org/api/core/class/Describable/#toString).
     *
     * @param description
     *  Replaces the current description according to the following rules:
     *  - If `description` is an [`Answerable`](https://serenity-js.org/api/core/#Answerable), it replaces the current description
     *  - If `description` is a [`MetaQuestion`](https://serenity-js.org/api/core/interface/MetaQuestion/), the current description is passed as `context` to `description.of(context)`, and the result replaces the current description
     */
    describedAs(description: Answerable<string> | MetaQuestion<Awaited<T>, Question<Promise<string>>>): this {
        super.setDescription(
            Question.isAMetaQuestion(description)
                ? description.of(this as Answerable<Awaited<T>>)
                : description
        );

        return this;
    }

    /**
     * Maps this question to one of a different type.
     *
     * ```ts
     * Question.about('number returned as string', actor => '42')   // returns: QuestionAdapter<string>
     *   .as(Number)                                                // returns: QuestionAdapter<number>
     * ```
     *
     * @param mapping
     */
    public as<O>(mapping: (answer: Awaited<T>) => Promise<O> | O): QuestionAdapter<O> {
        return Question.about<O>(f`${ this }.as(${ mapping })`, async actor => {
            const answer = (await actor.answer(this)) as Awaited<T>;
            return mapping(answer);
        });
    }
}

declare global {
    interface ProxyConstructor {
        new<Source_Type extends object, Target_Type extends object>(target: Source_Type, handler: ProxyHandler<Source_Type>): Target_Type;
    }
}

/* eslint-disable @typescript-eslint/indent */

/**
 * Describes an object recursively wrapped in [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter) proxies, so that:
 * - both methods and fields of the wrapped object can be used as [questions](https://serenity-js.org/api/core/class/Question/) or [interactions](https://serenity-js.org/api/core/class/Interaction/)
 * - method parameters of the wrapped object will accept [`Answerable<T>`](https://serenity-js.org/api/core/#Answerable)
 *
 * @group Questions
 */
export type QuestionAdapterFieldDecorator<Original_Type> = {
    [Field in keyof Omit<Original_Type, keyof QuestionStatement<Original_Type>>]:
        // is it a method?
        Original_Type[Field] extends (...args: infer OriginalParameters) => infer OriginalMethodResult
            // Workaround for TypeScript giving up too soon when resolving type aliases in lib.es2015.symbol.wellknown and lib.es2021.string
            ? Field extends 'replace' | 'replaceAll'
                ? (searchValue: Answerable<string | RegExp>, replaceValue: Answerable<string>) => QuestionAdapter<string>
                : (...args: { [P in keyof OriginalParameters]: Answerable<Awaited<OriginalParameters[P]>> }) =>
                    QuestionAdapter<Awaited<OriginalMethodResult>>
            // is it an object? wrap each field
            : Original_Type[Field] extends number | bigint | boolean | string | symbol | object
                ? QuestionAdapter<Awaited<Original_Type[Field]>>
                : any;
};
/* eslint-enable @typescript-eslint/indent */

/**
 * A union type representing a proxy object returned by [`Question.about`](https://serenity-js.org/api/core/class/Question/#about).
 *
 * [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter) proxies the methods and fields of the wrapped object recursively,
 * allowing them to be used as either a [`Question`](https://serenity-js.org/api/core/class/Question/) or an [`Interaction`](https://serenity-js.org/api/core/class/Interaction/).
 *
 * @group Questions
 */
export type QuestionAdapter<Answer_Type> =
    & Question<Promise<Answer_Type>>
    & Interaction
    & { isPresent(): Question<Promise<boolean>>; }  // more specialised Optional
    & QuestionAdapterFieldDecorator<Answer_Type>;

/**
 * An extension of [`QuestionAdapter`](https://serenity-js.org/api/core/#QuestionAdapter), that in addition to proxying methods and fields
 * of the wrapped object can also act as a [`MetaQuestion`](https://serenity-js.org/api/core/interface/MetaQuestion/).
 *
 * @group Questions
 */
export type MetaQuestionAdapter<Context_Type, Answer_Type> =
    & QuestionAdapter<Answer_Type>
    & MetaQuestion<Context_Type, QuestionAdapter<Answer_Type>>

/**
 * @package
 */
class QuestionStatement<Answer_Type> extends Interaction implements Question<Promise<Answer_Type>>, Optional {

    private answer: Answer_Type | Unanswered = new Unanswered();

    constructor(
        subject: Answerable<string>,
        private readonly body: (actor: AnswersQuestions & UsesAbilities, ...Parameters) => Promise<Answer_Type> | Answer_Type,
        location: FileSystemLocation = QuestionStatement.callerLocation(4),
    ) {
        super(subject, location);
    }

    /**
     * Returns a Question that resolves to `true` if resolving the `QuestionStatement`
     * returns a value other than `null` or `undefined`, and doesn't throw errors.
     */
    isPresent(): Question<Promise<boolean>> {
        return new IsPresent(this);
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<Answer_Type> {
        this.answer = await this.body(actor);
        return this.answer;
    }

    async performAs(actor: UsesAbilities & AnswersQuestions): Promise<void> {
        await this.body(actor);
    }

    [util.inspect.custom](depth: number, options: util.InspectOptionsStylized, inspect: typeof util.inspect): string {
        return inspectedObject(this.answer)(depth, options, inspect);
    }

    describedAs(description: Answerable<string> | MetaQuestion<Answer_Type, Question<Promise<string>>>): this {
        super.setDescription(
            Question.isAMetaQuestion(description)
                ? description.of(this)
                : description
        );

        return this;
    }

    as<O>(mapping: (answer: Awaited<Answer_Type>) => (Promise<O> | O)): QuestionAdapter<O> {
        return Question.about<O>(f`${ this }.as(${ mapping })`, async actor => {
            const answer = await actor.answer(this);

            if (! isDefined(answer)) {
                return undefined;   // eslint-disable-line unicorn/no-useless-undefined
            }

            return mapping(answer);
        });
    }
}

/**
 * @package
 */
class MetaQuestionStatement<Answer_Type, Supported_Context_Type extends Answerable<any>>
    extends QuestionStatement<Answer_Type>
    implements MetaQuestion<Supported_Context_Type, QuestionAdapter<Answer_Type>>
{
    constructor(
        subject: Answerable<string>,
        body: (actor: AnswersQuestions & UsesAbilities, ...Parameters) => Promise<Answer_Type> | Answer_Type,
        private readonly metaQuestionBody: (answerable: Answerable<Supported_Context_Type>) => QuestionAdapter<Answer_Type>,
    ) {
        super(subject, body);
    }

    of(answerable: Answerable<Supported_Context_Type>): QuestionAdapter<Answer_Type> {
        return Question.about(
            the`${ this } of ${ answerable }`,
            actor => actor.answer(this.metaQuestionBody(answerable))
        );
    }
}

/**
 * @package
 */
class IsPresent<T> extends Question<Promise<boolean>> {

    constructor(private readonly question: Question<T>) {
        super(f`${question}.isPresent()`);
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<boolean> {
        try {
            const answer = await actor.answer(this.question);

            if (answer === undefined || answer === null) {
                return false;
            }

            if (this.isOptional(answer)) {
                return await actor.answer(answer.isPresent());
            }

            return true;
        } catch {
            return false;
        }
    }

    private isOptional(maybeOptional: any): maybeOptional is Optional {
        return typeof maybeOptional === 'object'
            && Reflect.has(maybeOptional, 'isPresent');
    }
}

/**
 * @package
 */
function isDefined<T>(value: T): boolean {
    return value !== undefined
        && value !== null;
}

/**
 * @package
 */
const maxRecursiveCallsLimit = 100;

/**
 * @package
 */
async function recursivelyAnswer<K extends number | string | symbol, V> (
    actor: AnswersQuestions & UsesAbilities,
    answerable: Answerable<Partial<Record<K, Answerable<V>>>>,
    description: string,
    currentRecursion = 0,
): Promise<Record<K, V>> {
    if (currentRecursion >= maxRecursiveCallsLimit) {
        throw new LogicError(`Question.fromObject() has reached the limit of ${ maxRecursiveCallsLimit } recursive calls while trying to resolve ${ description }. Could it contain cyclic references?`);
    }

    const answer = await actor.answer(answerable) as any;

    if (isRecord(answer)) {
        const entries = Object.entries(answer);
        const answeredEntries = [];

        for (const [ key, value ] of entries) {
            answeredEntries.push([ key, await recursivelyAnswer(actor, value as any, description, currentRecursion + 1) ]);
        }

        return Object.fromEntries(answeredEntries) as Record<K, V>;
    }

    if (Array.isArray(answer)) {
        const answeredEntries: Array<V> = [];

        for (const item of answer) {
            answeredEntries.push(await recursivelyAnswer(actor, item, description, currentRecursion + 1) as V);
        }

        return answeredEntries as unknown as Record<K, V>;
    }

    return answer as Record<K, V>;
}

class MetaQuestionAboutValue<Answer_Type> implements MetaQuestion<Answer_Type, Question<Promise<Answer_Type>>> {
    of(answerable: Answerable<Answer_Type>): Question<Promise<Answer_Type>> {
        return new QuestionAboutValue<Answer_Type>(answerable);
    }

    toString(): string {
        return 'value';
    }
}

class QuestionAboutValue<Answer_Type>
    extends Question<Promise<Answer_Type>>
{
    constructor(private readonly context: Answerable<Answer_Type>) {
        super(QuestionAboutFormattedValue.of(context).toString());
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<Answer_Type> {
        return await actor.answer(this.context);
    }
}

class MetaQuestionAboutFormattedValue<Supported_Context_Type> implements MetaQuestion<Supported_Context_Type, Question<Promise<string>>> {
    static using<SCT>(options?: DescriptionFormattingOptions): MetaQuestion<SCT, Question<Promise<string>>> {
        return new MetaQuestionAboutFormattedValue(new ValueFormatter({
            ...ValueFormatter.defaultOptions,
            ...options,
        }));
    }

    constructor(private readonly formatter: ValueFormatter) {
    }

    of(context: Answerable<Supported_Context_Type>): Question<Promise<string>> & MetaQuestion<any, Question<Promise<string>>> {
        return new QuestionAboutFormattedValue(
            this.formatter,
            context,
        );
    }

    toString(): string {
        return 'formatted value';
    }
}

class QuestionAboutFormattedValue<Supported_Context_Type>
    extends Question<Promise<string>>
    implements MetaQuestion<any, Question<Promise<string>>>
{
    static of(context: Answerable<unknown>): Question<Promise<string>> & MetaQuestion<any, Question<Promise<string>>> {
        return new QuestionAboutFormattedValue(new ValueFormatter(ValueFormatter.defaultOptions), context);
    }

    constructor(
        private readonly formatter: ValueFormatter,
        private context?: Answerable<Supported_Context_Type>
    ) {
        const description = context === undefined
            ? 'formatted value'
            : formatter.format(context);

        super(description);
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities & { name: string }): Promise<string> {
        const answer = await actor.answer(this.context);

        return this.formatter.format(answer);
    }

    override async describedBy(actor: AnswersQuestions & UsesAbilities & { name: string }): Promise<string> {
        const unanswered = ! this.context
            || ! this.context['answer']
            || Unanswered.isUnanswered((this.context as any).answer);

        const answer = unanswered
            ? await actor.answer(this.context)
            : (this.context as any).answer;

        return this.formatter.format(answer);
    }

    of(context: Answerable<unknown>): Question<Promise<string>> & MetaQuestion<any, Question<Promise<string>>> {
        return new QuestionAboutFormattedValue(
            this.formatter,
            Question.isAMetaQuestion(this.context)
                ? this.context.of(context)
                : context,
        );
    }
}

class ValueFormatter {
    public static readonly defaultOptions = { maxLength: Number.POSITIVE_INFINITY };

    constructor(private readonly options: DescriptionFormattingOptions) {
    }

    format(value: unknown): string {
        if (value === null) {
            return 'null';
        }

        if (value === undefined) {
            return 'undefined';
        }

        if (typeof value === 'string') {
            return `"${ this.trim(value) }"`;
        }

        if (typeof value === 'symbol') {
            return `Symbol(${ this.trim(value.description) })`;
        }

        if (typeof value === 'bigint') {
            return `${ this.trim(value.toString()) }`;
        }

        if (ValueInspector.isPromise(value)) {
            return 'Promise';
        }

        if (Array.isArray(value)) {
            return value.length === 0
                ? '[ ]'
                : `[ ${ this.trim(value.map(item => this.format(item)).join(', ')) } ]`;
        }

        if (value instanceof Map) {
            return `Map(${ this.format(Object.fromEntries(value.entries())) })`;
        }

        if (value instanceof Set) {
            return `Set(${ this.format(Array.from(value.values())) })`;
        }

        if (ValueInspector.isDate(value)) {
            return `Date(${ value.toISOString() })`;
        }

        if (value instanceof RegExp) {
            return `${ value }`;
        }

        if (ValueInspector.hasItsOwnToString(value)) {
            return `${ this.trim(value.toString()) }`;
        }

        if (ValueInspector.isPlainObject(value)) {
            const stringifiedEntries = Object
                .entries(value)
                .reduce((acc, [ key, value ]) => acc.concat(`${ key }: ${ this.format(value) }`), [])
                .join(', ');

            return `{ ${ this.trim(stringifiedEntries) } }`;
        }

        if (typeof value === 'object') {
            const entries = significantFieldsOf(value)
                .map(field => [ field, (value as any)[field] ]);
            return `${ value.constructor.name }(${ this.format(Object.fromEntries(entries)) })`;
        }

        return String(value);
    }

    private trim(value: string): string {
        const ellipsis = '...';
        const oneLiner = value.replaceAll(/\n+/g, ' ');

        const maxLength = Math.max(ellipsis.length + 1, this.options.maxLength);

        return oneLiner.length > maxLength
            ? `${ oneLiner.slice(0, Math.max(0, maxLength) - ellipsis.length) }${ ellipsis }`
            : oneLiner;
    }
}