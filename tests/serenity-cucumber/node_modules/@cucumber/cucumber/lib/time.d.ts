/// <reference types="node" />
/// <reference types="node" />
import { performance } from 'perf_hooks';
import * as messages from '@cucumber/messages';
interface ProtectedTimingBuiltins {
    clearImmediate: typeof clearImmediate;
    clearInterval: typeof clearInterval;
    clearTimeout: typeof clearTimeout;
    Date: typeof Date;
    setImmediate: typeof setImmediate;
    setInterval: typeof setInterval;
    setTimeout: typeof setTimeout;
    performance: typeof performance;
}
declare const methods: Partial<ProtectedTimingBuiltins>;
export declare function durationBetweenTimestamps(startedTimestamp: messages.Timestamp, finishedTimestamp: messages.Timestamp): messages.Duration;
export declare function wrapPromiseWithTimeout<T>(promise: Promise<T>, timeoutInMilliseconds: number, timeoutMessage?: string): Promise<T>;
export default methods;
