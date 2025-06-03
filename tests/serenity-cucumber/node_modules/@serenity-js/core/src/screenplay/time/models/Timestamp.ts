import { ensure, isDefined, isInstanceOf, Predicate, TinyType } from 'tiny-types';
import { inspect } from 'util';

import { Duration } from './Duration';

/**
 * Represents a point in time.
 *
 * `Timestamp` makes it easier for you to work with information related to time, like [Serenity/JS domain events](https://serenity-js.org/api/core-events/class/DomainEvent/).
 *
 * ## Learn more
 * - [`Duration`](https://serenity-js.org/api/core/class/Duration/)
 * - [Date](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Date)
 *
 * @group Time
 */
export class Timestamp extends TinyType {
    static fromJSON(v: string): Timestamp {
        return new Timestamp(new Date(ensure(Timestamp.name, v, isSerialisedISO8601Date())));
    }

    static fromTimestampInSeconds(value: number): Timestamp {
        return Timestamp.fromTimestampInMilliseconds(value * 1000);
    }

    static fromTimestampInMilliseconds(value: number): Timestamp {
        return new Timestamp(new Date(value));
    }

    static now(): Timestamp {
        return new Timestamp();
    }

    constructor(public readonly value: Date = new Date()) {
        super();
        ensure(Timestamp.name, value, isDefined(), isInstanceOf(Date));
    }

    diff(another: Timestamp): Duration {
        ensure('timestamp', another, isDefined());
        return new Duration(Math.abs(this.toMilliseconds() - another.toMilliseconds()));
    }

    plus(duration: Duration): Timestamp {
        ensure('duration', duration, isDefined());
        return new Timestamp(new Date(this.toMilliseconds() + duration.inMilliseconds()));
    }

    less(duration: Duration): Timestamp {
        ensure('duration', duration, isDefined());
        return new Timestamp(new Date(this.toMilliseconds() - duration.inMilliseconds()));
    }

    isBefore(another: Timestamp): boolean {
        ensure('timestamp', another, isDefined());
        return this.value.getTime() < another.value.getTime();
    }

    isBeforeOrEqual(another: Timestamp): boolean {
        ensure('timestamp', another, isDefined());
        return this.value.getTime() <= another.value.getTime();
    }

    isAfter(another: Timestamp): boolean {
        ensure('timestamp', another, isDefined());
        return this.value.getTime() > another.value.getTime();
    }

    isAfterOrEqual(another: Timestamp): boolean {
        ensure('timestamp', another, isDefined());
        return this.value.getTime() >= another.value.getTime();
    }

    toMilliseconds(): number {
        return this.value.getTime();
    }

    toSeconds(): number {
        return Math.floor(this.toMilliseconds() / 1_000);
    }

    toJSON(): string {
        return this.value.toJSON();
    }

    toISOString(): string {
        return this.value.toISOString();
    }

    toString(): string {
        return this.toISOString();
    }

    [inspect.custom](): string {
        return `Timestamp(${ this.value.toISOString() })`;
    }
}

/**
 * Based on the implementation by Brock Adams:
 * - https://stackoverflow.com/a/3143231/264502 by Brock Adams
 * - https://www.w3.org/TR/NOTE-datetime
 */
function isSerialisedISO8601Date(): Predicate<string> {

    const yyyyMMdd = `\\d{4}-[01]\\d-[0-3]\\d`;
    const hh = `[0-2]\\d`;
    const mm = `[0-5]\\d`;
    const ss = `[0-5]\\d`;
    const ms = `\\d+`;
    const T = `[Tt\\s]`;
    const offset = `[+-]${hh}:${mm}|Z`;

    const pattern = new RegExp('^' + [
        // Full precision - YYYY-MM-DDThh:mm:ss.sss
        `(${yyyyMMdd}${T}${hh}:${mm}:${ss}\\.${ms}(${offset})?)`,
        // No milliseconds - YYYY-MM-DDThh:mm:ss
        `(${yyyyMMdd}${T}${hh}:${mm}:${ss}(${offset})?)`,
        // No seconds - YYYY-MM-DDThh:mm
        `(${yyyyMMdd}${T}${hh}:${mm}(${offset})?)`,
        // Just the date - YYYY-MM-DD
        `(${yyyyMMdd})`,
    ].join('|') + '$');

    return Predicate.to(`follow the ISO8601 format: YYYY-MM-DD[Thh:mm[:ss[.sss]]]`, (value: string) => {

        if (! pattern.test(value)) {
            return false;
        }

        const date = new Date(value);

        return date instanceof Date
            && ! Number.isNaN(date.getTime());
    });
}
