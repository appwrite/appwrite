import * as util from 'util'; // eslint-disable-line unicorn/import-style

import { ValueInspector } from './reflection';

export function inspectedObject<T>(value: T, allowFields?: Array<keyof T>): (depth: number, options: util.InspectOptionsStylized, inspect: typeof util.inspect) => string {
    return function (depth: number, options: util.InspectOptionsStylized, inspect: typeof util.inspect = util.inspect): string {
        const typeName = options.stylize(ValueInspector.typeOf(value), 'special');

        if (depth < 0) {
            return typeName;
        }

        const fields = Object.getOwnPropertyNames(value)
            .filter(field => typeof value[field] !== 'function')
            .filter(field => allowFields ? allowFields.includes(field as keyof T) : true)
            .sort();

        if (fields.length === 0) {
            return `${ typeName } { }`;
        }

        const newOptions = Object.assign({}, options, {
            depth: options?.depth > 0
                ? options.depth - 1
                : undefined,
        });

        const padding = ' '.repeat(2);

        const lines = fields.flatMap(
            field => `${ field }: ${ inspect(value[field], newOptions) }`
                .split('\n')
                .map(line => `${ padding }${ line }`)
        ).join('\n');

        return `${ typeName } {\n${ lines }\n}`;
    }
}
