"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.wrapPromiseWithTimeout = exports.durationBetweenTimestamps = void 0;
const perf_hooks_1 = require("perf_hooks");
const messages = __importStar(require("@cucumber/messages"));
const methods = {
    clearInterval: clearInterval.bind(global),
    clearTimeout: clearTimeout.bind(global),
    Date,
    setInterval: setInterval.bind(global),
    setTimeout: setTimeout.bind(global),
    performance: perf_hooks_1.performance,
};
if (typeof setImmediate !== 'undefined') {
    methods.setImmediate = setImmediate.bind(global);
    methods.clearImmediate = clearImmediate.bind(global);
}
function durationBetweenTimestamps(startedTimestamp, finishedTimestamp) {
    const durationMillis = messages.TimeConversion.timestampToMillisecondsSinceEpoch(finishedTimestamp) -
        messages.TimeConversion.timestampToMillisecondsSinceEpoch(startedTimestamp);
    return messages.TimeConversion.millisecondsToDuration(durationMillis);
}
exports.durationBetweenTimestamps = durationBetweenTimestamps;
async function wrapPromiseWithTimeout(promise, timeoutInMilliseconds, timeoutMessage = '') {
    let timeoutId;
    if (timeoutMessage === '') {
        timeoutMessage = `Action did not complete within ${timeoutInMilliseconds} milliseconds`;
    }
    const timeoutPromise = new Promise((resolve, reject) => {
        timeoutId = methods.setTimeout(() => {
            reject(new Error(timeoutMessage));
        }, timeoutInMilliseconds);
    });
    return await Promise.race([promise, timeoutPromise]).finally(() => methods.clearTimeout(timeoutId));
}
exports.wrapPromiseWithTimeout = wrapPromiseWithTimeout;
exports.default = methods;
//# sourceMappingURL=time.js.map