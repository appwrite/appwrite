import * as messages from './messages.js';
export declare function millisecondsSinceEpochToTimestamp(millisecondsSinceEpoch: number): messages.Timestamp;
export declare function millisecondsToDuration(durationInMilliseconds: number): messages.Duration;
export declare function timestampToMillisecondsSinceEpoch(timestamp: messages.Timestamp): number;
export declare function durationToMilliseconds(duration: messages.Duration): number;
export declare function addDurations(durationA: messages.Duration, durationB: messages.Duration): messages.Duration;
//# sourceMappingURL=TimeConversion.d.ts.map