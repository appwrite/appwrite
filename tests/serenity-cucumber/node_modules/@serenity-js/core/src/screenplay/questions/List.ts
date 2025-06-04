import { ListItemNotFoundError, LogicError } from '../../errors';
import { d } from '../../io';
import type { UsesAbilities } from '../abilities';
import type { Actor } from '../Actor';
import type { Answerable } from '../Answerable';
import type { MetaQuestionAdapter, QuestionAdapter } from '../Question';
import { Question } from '../Question';
import type { AnswersQuestions, ChainableMetaQuestion, MetaQuestion } from '../questions';
import { Task } from '../Task';
import type { Expectation } from './Expectation';
import { ExpectationMet } from './expectations';

/**
 * Serenity/JS Screenplay Pattern-style wrapper around [`Array`](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array)
 * and array-like structures - see [`PageElement`](https://serenity-js.org/api/web/class/PageElements/).
 *
 * @group Questions
 */
export abstract class List<Item_Type> extends Question<Promise<Array<Item_Type>>> {
    protected subject?: string;

    static of<IT, CT, RQT extends (Question<Promise<Array<IT>>> | Question<Array<IT>>)>(collection: Answerable<Array<IT>> & ChainableMetaQuestion<CT, RQT>): MetaList<CT, IT>;
    static of<IT>(collection: Answerable<Array<IT>>): List<IT>;
    static of<IT>(collection: unknown): unknown {
        if (Question.isAMetaQuestion<unknown, Question<Array<IT>>>(collection)) {
            return new MetaList(collection as Answerable<Array<IT>> & ChainableMetaQuestion<unknown, Question<Array<IT>>>);
        }

        return new ArrayList<IT>(collection as Answerable<Array<IT>>);
    }

    constructor(protected readonly collection: Answerable<Array<Item_Type>>) {
        super(d`${ collection }`);
    }

    forEach(callback: (current: CurrentItem<Item_Type>, index: number, items: Array<Item_Type>) => Promise<void> | void): Task {
        return new ForEachLoop(this.collection, this.toString(), callback);
    }

    abstract eachMappedTo<Mapped_Item_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Mapped_Item_Type> | Mapped_Item_Type>>
    ): List<Mapped_Item_Type>;

    abstract where<Answer_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Answer_Type> | Answer_Type>>,
        expectation: Expectation<Answer_Type>
    ): List<Item_Type>;

    abstract count(): QuestionAdapter<number>;

    abstract first(): QuestionAdapter<Item_Type>;

    abstract last(): QuestionAdapter<Item_Type>;

    abstract nth(index: number): QuestionAdapter<Item_Type>;

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<Array<Item_Type>> {
        const collection = await actor.answer(this.collection);

        if (! Array.isArray(collection)) {
            throw new LogicError(d`A List has to wrap an Array-compatible object. ${ collection } given.`);
        }

        return collection;
    }

    /**
     * @param {number} index
     */
    protected ordinal(index: number): string {
        const
            lastDigit     = Math.abs(index) % 10,
            lastTwoDigits = Math.abs(index) % 100;

        switch (true) {
            case (lastDigit === 1 && lastTwoDigits !== 11):
                return index + 'st';
            case (lastDigit === 2 && lastTwoDigits !== 12):
                return index + 'nd';
            case (lastDigit === 3 && lastTwoDigits !== 13):
                return index + 'rd';
            default:
                return index + 'th';
        }
    }
}

/**
 * @package
 */
class ArrayList<Item_Type> extends List<Item_Type> {

    override eachMappedTo<Mapped_Item_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Mapped_Item_Type> | Mapped_Item_Type>>,
    ): List<Mapped_Item_Type> {
        return new ArrayList(
            new EachMappedTo(this.collection, question, this.toString())
        );
    }

    override where<Answer_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Answer_Type> | Answer_Type>>,
        expectation: Expectation<Answer_Type>
    ): List<Item_Type> {
        return new ArrayList<Item_Type>(
            new Where(this.collection, question, expectation, this.toString())
        ) as this;
    }

    override count(): QuestionAdapter<number> {
        return Question.about(`the number of ${ this.toString() }`, async actor => {
            const items = await this.answeredBy(actor);
            return items.length;
        });
    }

    override first(): QuestionAdapter<Item_Type> {
        return Question.about(`the first of ${ this.toString() }`, async actor => {
            const items = await this.answeredBy(actor);

            if (items.length === 0) {
                throw new ListItemNotFoundError(d`Can't retrieve the first item from a list with 0 items: ${ items }`)
            }

            return items[0];
        });
    }

    override last(): QuestionAdapter<Item_Type> {
        return Question.about(`the last of ${ this.toString() }`, async actor => {
            const items = await this.answeredBy(actor);

            if (items.length === 0) {
                throw new ListItemNotFoundError(d`Can't retrieve the last item from a list with 0 items: ${ items }`)
            }

            return items.at(-1);
        });
    }

    override nth(index: number): QuestionAdapter<Item_Type> {
        return Question.about(`the ${ this.ordinal(index + 1) } of ${ this.toString() }`, async actor => {
            const items = await this.answeredBy(actor);

            if (index < 0 || items.length <= index) {
                throw new ListItemNotFoundError(`Can't retrieve the ${ this.ordinal(index) } item from a list with ${ items.length } items: ` + d`${ items }`)
            }

            return items[index];
        });
    }
}

/**
 * Serenity/JS Screenplay Pattern-style wrapper around
 * a [`ChainableMetaQuestion`](https://serenity-js.org/api/core/interface/ChainableMetaQuestion/) representing a collection
 * that can be resolved in `Supported_Context_Type` of another [`Question`](https://serenity-js.org/api/core/class/Question/).
 *
 * For example, [`PageElements.located`](https://serenity-js.org/api/web/class/PageElements/#located) returns `MetaList<PageElement>`,
 * which allows for the collection of page elements to be resolved in the context
 * of dynamically-provided root element.
 *
 * ```typescript
 * import { By, PageElements, PageElement } from '@serenity-js/web'
 *
 * const firstLabel = () =>
 *   PageElements.located(By.css('label'))
 *      .first()
 *      .describedAs('first label')
 *
 * const exampleForm = () =>
 *   PageElement.located(By.css('form#example1'))
 *      .describedAs('example form')
 *
 * const anotherExampleForm = () =>
 *   PageElement.located(By.css('form#example2'))
 *      .describedAs('another example form')
 *
 * // Next, you can compose the above questions dynamically with various "contexts":
 * //   firstLabel().of(exampleForm())
 * //   firstLabel().of(anotherExampleForm())
 * ```
 *
 * @group Questions
 */
export class MetaList<Supported_Context_Type, Item_Type>
    extends List<Item_Type>
    implements ChainableMetaQuestion<Supported_Context_Type, MetaList<Supported_Context_Type, Item_Type>>
{
    constructor(
        protected override readonly collection: Answerable<Array<Item_Type>> & ChainableMetaQuestion<Supported_Context_Type, Question<Promise<Array<Item_Type>>> | Question<Array<Item_Type>>>
    ) {
        super(collection);
    }

    of(context: Answerable<Supported_Context_Type>): MetaList<Supported_Context_Type, Item_Type> {
        return new MetaList<Supported_Context_Type, Item_Type>(
            this.collection.of(context)
        ).describedAs(this.toString() + d` of ${ context }`)
    }

    override eachMappedTo<Mapped_Item_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Mapped_Item_Type> | Mapped_Item_Type>>,
    ): MetaList<Supported_Context_Type, Mapped_Item_Type> {
        return new MetaList(
            new MetaEachMappedTo(this.collection, question, this.toString()),
        );
    }

    override where<Answer_Type>(
        question: MetaQuestion<Item_Type, Question<Promise<Answer_Type> | Answer_Type>>,
        expectation: Expectation<Answer_Type>
    ): MetaList<Supported_Context_Type, Item_Type> {
        return new MetaList<Supported_Context_Type, Item_Type>(
            new MetaWhere(this.collection, question, expectation, this.toString())
        ) as this;
    }

    override count(): MetaQuestionAdapter<Supported_Context_Type, number> {
        return Question.about(`the number of ${ this.toString() }`,
            async actor => {
                const items = await this.answeredBy(actor);
                return items.length;
            },
            (parent: Answerable<Supported_Context_Type>) => this.of(parent).count()
        );
    }

    override first(): MetaQuestionAdapter<Supported_Context_Type, Item_Type> {
        return Question.about(`the first of ${ this.toString() }`,
            async actor => {
                const items = await this.answeredBy(actor);

                if (items.length === 0) {
                    throw new ListItemNotFoundError(d`Can't retrieve the first item from a list with 0 items: ${ items }`)
                }

                return items[0];
            },
            (parent: Answerable<Supported_Context_Type>) => this.of(parent).first()
        );
    }

    override last(): MetaQuestionAdapter<Supported_Context_Type, Item_Type> {
        return Question.about(`the last of ${ this.toString() }`,
            async actor => {
                const items = await this.answeredBy(actor);

                if (items.length === 0) {
                    throw new ListItemNotFoundError(d`Can't retrieve the last item from a list with 0 items: ${ items }`)
                }

                return items.at(-1);
            },
            (parent: Answerable<Supported_Context_Type>) => this.of(parent).last()
        );
    }

    override nth(index: number): MetaQuestionAdapter<Supported_Context_Type, Item_Type> {
        return Question.about(`the ${ this.ordinal(index + 1) } of ${ this.toString() }`,
            async actor => {
                const items = await this.answeredBy(actor);

                if (index < 0 || items.length <= index) {
                    throw new ListItemNotFoundError(`Can't retrieve the ${ this.ordinal(index) } item from a list with ${ items.length } items: ` + d`${ items }`)
                }

                return items[index];
            },
            (parent: Answerable<Supported_Context_Type>) => this.of(parent).nth(index)
        );
    }
}

/**
 * @package
 */
class Where<Item_Type, Answer_Type>
    extends Question<Promise<Array<Item_Type>>>
{
    constructor(
        protected readonly collection: Answerable<Array<Item_Type>>,
        protected readonly question: MetaQuestion<Item_Type, Question<Promise<Answer_Type> | Answer_Type>>,
        protected readonly expectation: Expectation<Answer_Type>,
        originalSubject: string,
    ) {
        const prefix = collection instanceof Where
            ? ' and'
            : ' where';

        super(originalSubject + prefix + d` ${ question } does ${ expectation }`);
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<Array<Item_Type>> {
        try {
            const collection    = await actor.answer(this.collection);
            const results: Item_Type[] = [];

            for (const item of collection) {
                const actual = this.question.of(item) as Answerable<Answer_Type>;
                const expectationOutcome = await actor.answer(this.expectation.isMetFor(actual));

                if (expectationOutcome instanceof ExpectationMet) {
                    results.push(item);
                }
            }

            return results;
        }
        catch (error) {
            throw new LogicError(d`Couldn't check if ${ this.question } of an item of ${ this.collection } does ${ this.expectation }: ` + error.message, error);
        }
    }
}

/**
 * @package
 */
class MetaWhere<Supported_Context_Type, Item_Type, Answer_Type>
    extends Where<Item_Type, Answer_Type>
    implements ChainableMetaQuestion<Supported_Context_Type, Question<Promise<Array<Item_Type>>> | Question<Array<Item_Type>>>
{
    of(context: Answerable<Supported_Context_Type>): MetaWhere<Supported_Context_Type, Item_Type, Answer_Type> {
        return new MetaWhere<Supported_Context_Type, Item_Type, Answer_Type>(
            (this.collection as Answerable<Array<Item_Type>> & ChainableMetaQuestion<Supported_Context_Type, Question<Promise<Array<Item_Type>>> | Question<Array<Item_Type>>>).of(context),
            this.question,
            this.expectation,
            this.toString()
        );
    }
}

/**
 * @package
 */
class EachMappedTo<Item_Type, Mapped_Item_Type> extends Question<Promise<Array<Mapped_Item_Type>>> {

    constructor(
        protected readonly collection: Answerable<Array<Item_Type>>,
        protected readonly mapping: MetaQuestion<Item_Type, Question<Promise<Mapped_Item_Type> | Mapped_Item_Type>>,
        originalSubject: string,
    ) {
        super(originalSubject + d` mapped to ${ mapping }`);
    }

    async answeredBy(actor: AnswersQuestions & UsesAbilities): Promise<Array<Mapped_Item_Type>> {
        const collection: Array<Item_Type> = await actor.answer(this.collection);

        const mapped: Mapped_Item_Type[] = [];

        for (const item of collection) {
            mapped.push(await actor.answer(this.mapping.of(item)))
        }

        return mapped;
    }
}

/**
 * @package
 */
class MetaEachMappedTo<Supported_Context_Type, Item_Type, Mapped_Item_Type>
    extends EachMappedTo<Item_Type, Mapped_Item_Type>
{
    of(context: Answerable<Supported_Context_Type>): MetaEachMappedTo<Supported_Context_Type, Item_Type, Mapped_Item_Type> {
        return new MetaEachMappedTo<Supported_Context_Type, Item_Type, Mapped_Item_Type>(
            (this.collection as Answerable<Array<Item_Type>> & ChainableMetaQuestion<Supported_Context_Type, Question<Promise<Array<Item_Type>>> | Question<Array<Item_Type>>>).of(context),
            this.mapping,
            this.toString()
        );
    }
}

/**
 * @package
 */
class ForEachLoop<Item_Type> extends Task {

    constructor(
        private readonly collection: Answerable<Array<Item_Type>>,
        private readonly subject: string,
        private readonly fn: (current: CurrentItem<Item_Type>, index: number, items: Array<Item_Type>) => Promise<void> | void,
    ) {
        super(`#actor iterates over ${ subject }`);
    }

    async performAs(actor: Actor): Promise<void> {
        const collection: Array<Item_Type> = await actor.answer(this.collection);

        for (const [index, item] of collection.entries()) {
            await this.fn({ actor, item }, index, collection);
        }
    }
}

/**
 * @group Questions
 */
export interface CurrentItem<Item_Type> {
    item: Item_Type;
    actor: Actor;
}
