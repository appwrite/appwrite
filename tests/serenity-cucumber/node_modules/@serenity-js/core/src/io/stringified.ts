import { inspect } from 'util';

import type { Answerable } from '../screenplay/Answerable';
import { Question } from '../screenplay/Question';
import { ValueInspector } from './reflection/ValueInspector';

const indentationPrefix = '  ';

interface StringifyConfig {
    inline: boolean;
    indentationLevel?: number;
    markQuestions?: boolean;
}

/**
 * Provides a human-readable description of the [`Answerable<T>`](https://serenity-js.org/api/core/#Answerable).
 * Similar to [Node util~inspect](https://nodejs.org/api/util.html#utilinspectobject-options).
 *
 * @param value
 * @param config
 *  - inline - Return a single-line string instead of the default potentially multi-line description
 *  - markQuestions - Surround the description of async values, such as Promises and Questions with <<value>>
 */
export function stringified(value: Answerable<any>, config?: StringifyConfig): string {

    const { indentationLevel, inline, markQuestions } = { indentationLevel: 0, inline: false, markQuestions: false, ...config };

    if (! isDefined(value)) {
        return inspect(value);
    }

    if (Array.isArray(value)) {
        return stringifiedArray(value, { indentationLevel, inline, markQuestions });
    }

    if (ValueInspector.isPromise(value)) {
        return markAs('Promise', markQuestions);
    }

    if (Question.isAQuestion(value)) {
        return markAs(value.toString(), markQuestions);
    }

    if (ValueInspector.isDate(value)) {
        return value.toISOString();
    }

    if (ValueInspector.hasItsOwnToString(value)) {
        return value.toString();
    }

    if (ValueInspector.isInspectable(value)) {
        return value.inspect();
    }

    if (ValueInspector.isFunction(value)) {
        return hasName(value)
            ? value.name
            : markAs(`Function`, true);
    }

    if (! ValueInspector.hasCustomInspectionFunction(value) && ValueInspector.isPlainObject(value) && isSerialisableAsJSON(value)) {
        return stringifiedToJson(value, { indentationLevel, inline, markQuestions });
    }

    return inspect(value, { breakLength: Number.POSITIVE_INFINITY, compact: inline ? 3 : false, sorted: false  });
}

function indented(line: string, config: { inline: boolean; indentationLevel?: number }): string {
    const indentation = config.inline
        ? ''
        : indentationPrefix.repeat(config.indentationLevel || 0);

    return indentation + line;
}

function stringifiedToJson(value: any, config: StringifyConfig): string {
    const jsonLineIndentation = config.inline ? 0 : indentationPrefix.length;

    const [ first, ...rest ] = JSON.stringify(value, undefined, jsonLineIndentation).split('\n');

    return [
        first,
        ...rest.map(line => indented(line, config))
    ].join('\n');
}

function stringifiedArray(value: any[], config: StringifyConfig): string {
    const lineSeparator = config.inline ? ' ' : '\n';

    const inspectedItem = (item: unknown, index: number) => {
        const nestedItemConfig = { ...config, indentationLevel: config.indentationLevel + 1 }
        return [
            indented('', nestedItemConfig),
            stringified(item, nestedItemConfig),
            index < value.length - 1 ? ',' : ''
        ].join('')
    }

    return [
        `[`,
        ...value.map(inspectedItem),
        indented(']', config),
    ].join(lineSeparator);
}

function markAs(value: string, markValue: boolean): string {
    const [ left, right ] = markValue && ! value.startsWith('<<')
        ? [ '<<', '>>' ]
        : [ '', '' ];

    return [ left, value, right ].join('');
}

/**
 * Checks if the value is defined
 *
 * @param v
 */
function isDefined(v: Answerable<any>) {
    return !! v;
}

/**
 * Checks if the value is has a property called 'name' with a non-empty value.
 *
 * @param v
 */
function hasName(v: any): v is { name: string } {
    return typeof (v as any).name === 'string' && (v as any).name !== '';
}

/**
 * Checks if the value is a JSON object that can be stringified
 *
 * @param v
 */
function isSerialisableAsJSON(v: any): v is object {    // eslint-disable-line @typescript-eslint/ban-types
    try {
        JSON.stringify(v);

        return true;
    } catch {
        return false;
    }
}
