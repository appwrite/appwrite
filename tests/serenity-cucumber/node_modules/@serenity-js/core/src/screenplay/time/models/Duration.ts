import type { JSONObject} from 'tiny-types';
import { ensure, isGreaterThanOrEqualTo, isInteger, TinyType } from 'tiny-types';

/**
 * Represents a duration in milliseconds between two [timestamps](https://serenity-js.org/api/core/class/Timestamp/).
 *
 * @group Time
 */
export class Duration extends TinyType {

    private static msPerSecond = 1000;
    private static msPerMinute = Duration.msPerSecond * 60;
    private static msPerHour   = Duration.msPerMinute * 60;
    private static msPerDay    = Duration.msPerHour * 24;
    private static msPerYear   = Duration.msPerDay * 365;

    static ofMilliseconds   = (milliseconds: number): Duration => new Duration(milliseconds);
    static ofSeconds        = (seconds: number): Duration      => Duration.ofMilliseconds(seconds  * Duration.msPerSecond);
    static ofMinutes        = (minutes: number): Duration      => Duration.ofMilliseconds(minutes  * Duration.msPerMinute);
    static ofHours          = (hours: number): Duration        => Duration.ofMilliseconds(hours    * Duration.msPerHour);
    static ofDays           = (days: number): Duration         => Duration.ofMilliseconds(days     * Duration.msPerDay);
    static ofYears          = (years: number): Duration        => Duration.ofMilliseconds(years    * Duration.msPerYear);

    static fromJSON({ milliseconds }: { milliseconds: number }): Duration {
        return Duration.ofMilliseconds(milliseconds);
    }

    constructor(private readonly milliseconds: number) {
        super();
        ensure('duration', milliseconds, isInteger(), isGreaterThanOrEqualTo(0));
    }

    isLessThan(another: Duration): boolean {
        return this.milliseconds < another.milliseconds;
    }

    isLessThanOrEqualTo(another: Duration): boolean {
        return this.milliseconds <= another.milliseconds;
    }

    isGreaterThan(another: Duration): boolean {
        return this.milliseconds > another.milliseconds;
    }

    isGreaterThanOrEqualTo(another: Duration): boolean {
        return this.milliseconds >= another.milliseconds;
    }

    plus(another: Duration): Duration {
        return new Duration(this.milliseconds + another.milliseconds);
    }

    inMilliseconds(): number {
        return this.milliseconds;
    }

    toString(): string {
        const ms = this.milliseconds;

        const levels: Array<[ number, string ]> = [
            [ Math.floor(   ms / Duration.msPerYear), 'y'],
            [ Math.floor(  (ms % Duration.msPerYear) / Duration.msPerDay), 'd'],
            [ Math.floor( ((ms % Duration.msPerYear) % Duration.msPerDay) / Duration.msPerHour), 'h'],
            [ Math.floor((((ms % Duration.msPerYear) % Duration.msPerDay) % Duration.msPerHour) / Duration.msPerMinute), 'm'],
            [ Math.floor((((ms % Duration.msPerYear) % Duration.msPerDay) % Duration.msPerHour) % Duration.msPerMinute / Duration.msPerSecond), 's'],
            [ (((ms % Duration.msPerYear) % Duration.msPerDay) % Duration.msPerHour) % Duration.msPerMinute % Duration.msPerSecond, 'ms'],
        ];

        return levels.reduce((acc, l, i) => l[0] > 0 || i === levels.length
            ? `${ acc } ${ l[0] }${ l[1] }`
            : acc,
        '').trim() || '0ms';
    }

    toJSON(): JSONObject {
        return {
            milliseconds: this.milliseconds,
        }
    }
}
