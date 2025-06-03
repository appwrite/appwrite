import type { JSONObject, JSONValue} from 'tiny-types';
import { ensure, isArray, isDefined, TinyType } from 'tiny-types';

import { inspected, ValueInspector } from '../../../io';
import { Name } from '../../../model';
import { Unanswered } from '../Unanswered';

/**
 * Used with [`ExpectationOutcome`](https://serenity-js.org/api/core/class/ExpectationOutcome/) to describe an [`Expectation`](https://serenity-js.org/api/core/class/Expectation/) and the arguments it's been executed with.
 *
 * @group Expectations
 */
export class ExpectationDetails extends TinyType {

    static of(functionName: string, ...functionArguments: Array<JSONValue | ExpectationDetails | Unanswered>): ExpectationDetails {
        return new ExpectationDetails(new Name(functionName), functionArguments);
    }

    static fromJSON(o: JSONObject): ExpectationDetails {
        return new ExpectationDetails(
            Name.fromJSON(o.name as string),
            (o.args as Array<{ type: string, value: JSONValue }>).map(arg => {
                if (arg.type === Unanswered.name) {
                    return new Unanswered();
                }
                if (arg.type === ExpectationDetails.name) {
                    return ExpectationDetails.fromJSON(arg.value as JSONObject)
                }
                // must be a JSONValue then
                return arg.value;
            }),
        );
    }

    protected constructor(
        public readonly name: Name,
        public readonly args: Array<JSONValue | ExpectationDetails | Unanswered>,
    ) {
        super();
        ensure('name', name, isDefined());
        ensure('args', args, isArray());
    }

    toString(): string {
        const argumentValues = this.args.map(arg =>
            arg instanceof ExpectationDetails
                ? arg.toString()
                : inspected(arg, { compact: true })
        ).join(', ');

        return `${ this.name.value }(${ argumentValues })`;
    }

    toJSON(): { name: string, args: Array<{ type: string, value: JSONValue }> } {
        return {
            name: this.name.value,
            args: this.args.map(arg => ({
                type: ValueInspector.typeOf(arg),
                value: arg['toJSON']
                    ? (arg as any).toJSON()
                    : arg,
            })),
        }
    }
}
