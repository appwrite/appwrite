import type { ArrayChange, Change } from 'diff';
import { diffArrays, diffJson } from 'diff';
import { equal } from 'tiny-types/lib/objects';
import { types } from 'util';

import { inspected, ValueInspector } from '../io';
import { Unanswered } from '../screenplay/questions/Unanswered';
import type { DiffFormatter } from './diff';
import { AnsiDiffFormatter } from './diff/AnsiDiffFormatter';
import type { ErrorOptions } from './ErrorOptions';
import type { RuntimeError } from './model';

/**
 * Generates Serenity/JS [`RuntimeError`](https://serenity-js.org/api/core/class/RuntimeError/) objects based
 * on the provided [configuration](https://serenity-js.org/api/core/interface/ErrorOptions/).
 *
 * @group Errors
 */
export class ErrorFactory {

    constructor(private readonly formatter: DiffFormatter = new AnsiDiffFormatter()) {
    }

    create<RE extends RuntimeError>(errorType: new (...args: any[]) => RE, options: ErrorOptions): RE {

        const message = [
            this.title(options.message),
            options.expectation && `\nExpectation: ${ options.expectation }`,
            options.diff && ('\n' + this.diffFrom(options.diff)),
            options.location && (`    at ${ options.location.path.value }:${ options.location.line }:${ options.location.column }`),
        ].
        filter(Boolean).
        join('\n');

        return new errorType(message, options?.cause) as unknown as RE;
    }

    private title(value: string): string {
        return String(value).trim();
    }

    private diffFrom(diff: { expected: unknown, actual: unknown }): string {
        return new Diff(diff.expected, diff.actual)
            .lines()
            .map(line => line.decorated(this.formatter))
            .join('\n');
    }
}

type DiffType = 'expected' | 'received' | 'unchanged';

class DiffLine {
    private static markers: Record<DiffType, string> = {
        'expected':  '- ',
        'received':  '+ ',
        'unchanged': '  ',
    }

    static empty = () =>
        new DiffLine('unchanged', '');

    static unchanged = (line: any) =>
        new DiffLine('unchanged', String(line));

    static expected = (line: any) =>
        new DiffLine('expected', String(line));

    static received = (line: any) =>
        new DiffLine('received', String(line));

    static changed = (change: Change | ArrayChange<any>, line: string) => {
        if (change.removed) {
            return this.expected(line);
        }

        if (change.added) {
            return this.received(line);
        }

        return this.unchanged(line);
    }

    private constructor(
        private readonly type: DiffType,
        private readonly value: string,
    ) {
    }

    prependMarker(): DiffLine {
        return this.prepend(this.marker());
    }

    appendMarker(): DiffLine {
        return this.append(this.marker());
    }

    prepend(text: any): DiffLine {
        return new DiffLine(this.type, String(text) + this.value);
    }

    append(text: any): DiffLine {
        return new DiffLine(this.type, this.value + String(text));
    }

    decorated(decorator: DiffFormatter): string {
        return decorator[this.type](this.value);
    }

    private marker(): string {
        return DiffLine.markers[this.type];
    }
}

class DiffValue {
    private nameAndType: string;
    private readonly summary?: string;
    private changes?: string;

    public desiredNameFieldLength: number;

    constructor(
        name: string,
        public readonly value: unknown,
    ) {
        this.nameAndType            = `${ name } ${ ValueInspector.typeOf(value) }`;
        this.desiredNameFieldLength = this.nameAndType.length;
        this.summary                = this.summaryOf(value);
    }

    withDesiredFieldLength(columns: number): this {
        this.desiredNameFieldLength = columns;
        return this;
    }

    hasSummary() {
        return this.summary !== undefined;
    }

    type(): string {
        return ValueInspector.typeOf(this.value);
    }

    isComplex(): boolean {
        return (typeof this.value === 'object' || types.isProxy(this.value))
            && ! (this.value instanceof RegExp)
            && ! (this.value instanceof Unanswered);
    }

    isArray(): boolean {
        return Array.isArray(this.value);
    }

    isComparableAsJson() {
        if (! this.value || this.value instanceof Unanswered) {
            return false;
        }

        return ValueInspector.isPlainObject(this.value)
            || this.value['toJSON'];
    }

    toString(): string {
        const labelWidth = this.desiredNameFieldLength - this.nameAndType.length;

        return [
            this.nameAndType,
            this.summary && ': '.padEnd(labelWidth + 2),
            this.summary,
            this.changes && this.changes.padStart(labelWidth + 5),
        ].
        filter(Boolean).
        join('');
    }

    private summaryOf(value: unknown): string | undefined {
        if (value instanceof Date) {
            return value.toISOString();
        }

        const isDefined = value !== undefined && value !== null;

        if (isDefined && (ValueInspector.isPrimitive(value) || value instanceof RegExp)) {
            return String(value);
        }

        return undefined;
    }
}

class Diff {
    private readonly diff: DiffLine[];

    constructor(
        expectedValue: unknown,
        actualValue: unknown,
    ) {
        this.diff = this.diffFrom(expectedValue, actualValue);
    }

    private diffFrom(expectedValue: unknown, actualValue: unknown): DiffLine[] {
        const { expected, actual } = this.aligned(
            new DiffValue('Expected', expectedValue),
            new DiffValue('Received', actualValue)
        );

        if (this.shouldRenderActualValueOnly(expected, actual)) {
            return this.renderActualValue(expected, actual);
        }

        if (this.shouldRenderJsonDiff(expected, actual)) {
            return this.renderJsonDiff(expected, actual);
        }

        if (this.shouldRenderArrayDiff(expected, actual)) {
            return this.renderArrayDiff(expected, actual);
        }

        return [
            DiffLine.expected(expected),
            DiffLine.received(actual),
            DiffLine.empty(),
        ]
    }

    private shouldRenderActualValueOnly(expected: DiffValue, actual: DiffValue): boolean {
        return actual.isComplex()
            && ! actual.hasSummary()
            && expected.type() !== actual.type();
    }

    private shouldRenderJsonDiff(expected: DiffValue, actual: DiffValue): boolean {
        return expected.isComparableAsJson()
            && actual.isComparableAsJson()
            && ! expected.hasSummary()
            && ! actual.hasSummary()
    }

    private shouldRenderArrayDiff(expected: DiffValue, actual: DiffValue): boolean {
        return expected.isArray()
            && actual.isArray();
    }

    private aligned(expected: DiffValue, actual: DiffValue): { expected: DiffValue, actual: DiffValue } {
        const maxFieldLength = Math.max(
            expected.desiredNameFieldLength,
            actual.desiredNameFieldLength
        );

        return {
            expected: expected.withDesiredFieldLength(maxFieldLength),
            actual:   actual.withDesiredFieldLength(maxFieldLength)
        };
    }

    private renderActualValue(expected: DiffValue, actual: DiffValue): DiffLine[] {

        const lines = inspected(actual.value)
            .split('\n')
            .map(DiffLine.unchanged);

        return [
            DiffLine.expected(expected),
            DiffLine.received(actual),
            DiffLine.empty(),
            ...lines,
            DiffLine.empty(),
        ];
    }

    private renderJsonDiff(expected: DiffValue, actual: DiffValue): DiffLine[] {
        const changes = diffJson(expected.value as object, actual.value as object);

        const lines = changes.reduce((acc, change) => {
            const changedLines = change.value.trimEnd().split('\n');
            return acc.concat(
                changedLines.map(line => DiffLine.changed(change, line).prependMarker())
            )
        }, []);

        const { added, removed } = this.countOf(changes);

        return [
            DiffLine.expected(expected).append('  ').appendMarker().append(`${ removed }`),
            DiffLine.received(actual).append('  ').appendMarker().append(`${ added }`),
            DiffLine.empty(),
            ...lines,
            DiffLine.empty(),
        ];
    }

    private renderArrayDiff(expected: DiffValue, actual: DiffValue): DiffLine[]  {
        const changes = diffArrays(expected.value as Array<unknown>, actual.value as Array<unknown>, { comparator: equal } );

        const lines = changes.reduce((acc, change) => {
            const items = change.value;
            return acc.concat(
                items.map(item =>
                    DiffLine.changed(change, inspected(item, { compact: true }))
                        .prepend('  ')
                        .prependMarker()
                )
            );
        }, []);

        const { added, removed } = this.countOf(changes);

        return [
            DiffLine.expected(expected).append('  ').appendMarker().append(`${ removed }`),
            DiffLine.received(actual).append('  ').appendMarker().append(`${ added }`),
            DiffLine.empty(),
            DiffLine.unchanged('  ['),
            ...lines,
            DiffLine.unchanged('  ]'),
            DiffLine.empty(),
        ]
    }

    private countOf(changes: Array<Change | ArrayChange<unknown>>): { added: number, removed: number } {
        return changes.reduce(({ removed, added }, change) => {
            return {
                removed: removed + (change.removed ? change.count : 0),
                added:   added +   (change.added ? change.count : 0),
            }
        }, { removed: 0, added: 0 });
    }

    lines(): DiffLine[] {
        return this.diff;
    }
}
