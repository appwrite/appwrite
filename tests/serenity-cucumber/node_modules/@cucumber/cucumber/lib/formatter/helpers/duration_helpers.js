"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.durationToNanoseconds = void 0;
const NANOS_IN_SECOND = 1000000000;
function durationToNanoseconds(duration) {
    return Math.floor(duration.seconds * NANOS_IN_SECOND + duration.nanos);
}
exports.durationToNanoseconds = durationToNanoseconds;
//# sourceMappingURL=duration_helpers.js.map