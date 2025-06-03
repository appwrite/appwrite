import { Duration, Timestamp } from '@cucumber/messages';
/**
 * A utility for timing test run operations and returning duration and
 * timestamp objects in messages-compatible formats
 */
export interface IStopwatch {
    start: () => IStopwatch;
    stop: () => IStopwatch;
    duration: () => Duration;
    timestamp: () => Timestamp;
}
export declare const create: (base?: Duration) => IStopwatch;
