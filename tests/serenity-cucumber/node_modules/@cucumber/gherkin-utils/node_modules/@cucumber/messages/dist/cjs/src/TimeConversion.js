"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.addDurations = exports.durationToMilliseconds = exports.timestampToMillisecondsSinceEpoch = exports.millisecondsToDuration = exports.millisecondsSinceEpochToTimestamp = void 0;
var MILLISECONDS_PER_SECOND = 1e3;
var NANOSECONDS_PER_MILLISECOND = 1e6;
var NANOSECONDS_PER_SECOND = 1e9;
function millisecondsSinceEpochToTimestamp(millisecondsSinceEpoch) {
    return toSecondsAndNanos(millisecondsSinceEpoch);
}
exports.millisecondsSinceEpochToTimestamp = millisecondsSinceEpochToTimestamp;
function millisecondsToDuration(durationInMilliseconds) {
    return toSecondsAndNanos(durationInMilliseconds);
}
exports.millisecondsToDuration = millisecondsToDuration;
function timestampToMillisecondsSinceEpoch(timestamp) {
    var seconds = timestamp.seconds, nanos = timestamp.nanos;
    return toMillis(seconds, nanos);
}
exports.timestampToMillisecondsSinceEpoch = timestampToMillisecondsSinceEpoch;
function durationToMilliseconds(duration) {
    var seconds = duration.seconds, nanos = duration.nanos;
    return toMillis(seconds, nanos);
}
exports.durationToMilliseconds = durationToMilliseconds;
function addDurations(durationA, durationB) {
    var seconds = +durationA.seconds + +durationB.seconds;
    var nanos = durationA.nanos + durationB.nanos;
    if (nanos >= NANOSECONDS_PER_SECOND) {
        seconds += 1;
        nanos -= NANOSECONDS_PER_SECOND;
    }
    return { seconds: seconds, nanos: nanos };
}
exports.addDurations = addDurations;
function toSecondsAndNanos(milliseconds) {
    var seconds = Math.floor(milliseconds / MILLISECONDS_PER_SECOND);
    var nanos = Math.floor((milliseconds % MILLISECONDS_PER_SECOND) * NANOSECONDS_PER_MILLISECOND);
    return { seconds: seconds, nanos: nanos };
}
function toMillis(seconds, nanos) {
    var secondMillis = +seconds * MILLISECONDS_PER_SECOND;
    var nanoMillis = nanos / NANOSECONDS_PER_MILLISECOND;
    return secondMillis + nanoMillis;
}
//# sourceMappingURL=TimeConversion.js.map