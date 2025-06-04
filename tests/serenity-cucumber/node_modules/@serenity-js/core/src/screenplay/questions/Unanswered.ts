import type { JSONValue} from 'tiny-types';
import { TinyType } from 'tiny-types';
import * as util from 'util';   // eslint-disable-line unicorn/import-style

/**
 * A placeholder value signifying that a [`Question`](https://serenity-js.org/api/core/class/Question/)
 * has not been answered by an [`Actor`](https://serenity-js.org/api/core/class/Actor/) when producing an [`ExpectationOutcome`](https://serenity-js.org/api/core/class/ExpectationOutcome/).
 * This happens when Serenity/JS decides that answering a given question
 * won't affect the outcome.
 *
 * For example, making the actor answer questions about the expected value
 * and the actual value of each list item is unnecessary if we already know that the list itself is empty.
 *
 * @group Questions
 */
export class Unanswered extends TinyType {
    static isUnanswered(value: unknown): value is Unanswered {
        return value instanceof Unanswered;
    }

    [util.inspect.custom](): string {
        return `<<unanswered>>`;
    }

    toString(): string {
        return 'unanswered';
    }

    toJSON(): JSONValue {
        return undefined;   // eslint-disable-line unicorn/no-useless-undefined
    }
}
