import { ValueInspector } from '../../io/reflection/ValueInspector';
import type { UsesAbilities } from '../abilities/UsesAbilities';
import type { Answerable } from '../Answerable';
import type { AnswersQuestions } from './AnswersQuestions';

const descriptionField = Symbol('description');

/**
 * @group Questions
 */
export abstract class Describable {

    private [descriptionField]: Answerable<string>;

    protected constructor(description: Answerable<string>) {
        this[descriptionField] = description;
    }

    /**
     * Resolves the description of this object in the context of the provided `actor`.
     *
     * @param actor
     */
    async describedBy(actor: AnswersQuestions & UsesAbilities & { name: string }): Promise<string> {
        const description = await actor.answer(this[descriptionField]);

        return description.replaceAll('#actor', actor.name);
    }

    protected setDescription(description: Answerable<string>): void {
        this[descriptionField] = description;
    }

    protected getDescription(): Answerable<string> {
        return this[descriptionField];
    }

    /**
     * Returns a human-readable description of this object.
     */
    toString(): string {
        if (ValueInspector.isPromise(this[descriptionField])) {
            return 'Promise';
        }

        return String(this[descriptionField]);
    }
}
