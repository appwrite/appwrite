"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var index_js_1 = require("../src/index.js");
var TimeConversion_js_1 = require("../src/TimeConversion.js");
var durationToMilliseconds = index_js_1.TimeConversion.durationToMilliseconds, millisecondsSinceEpochToTimestamp = index_js_1.TimeConversion.millisecondsSinceEpochToTimestamp, millisecondsToDuration = index_js_1.TimeConversion.millisecondsToDuration, timestampToMillisecondsSinceEpoch = index_js_1.TimeConversion.timestampToMillisecondsSinceEpoch;
describe('TimeConversion', function () {
    it('converts legacy string seconds', function () {
        var duration = {
            // @ts-ignore
            seconds: '3',
            nanos: 40000,
        };
        var millis = durationToMilliseconds(duration);
        assert_1.default.strictEqual(millis, 3000.04);
    });
    it('converts to and from milliseconds since epoch', function () {
        var millisecondsSinceEpoch = Date.now();
        var timestamp = millisecondsSinceEpochToTimestamp(millisecondsSinceEpoch);
        var jsEpochMillisAgain = timestampToMillisecondsSinceEpoch(timestamp);
        assert_1.default.strictEqual(jsEpochMillisAgain, millisecondsSinceEpoch);
    });
    it('converts to and from milliseconds duration', function () {
        var durationInMilliseconds = 1234;
        var duration = millisecondsToDuration(durationInMilliseconds);
        var durationMillisAgain = durationToMilliseconds(duration);
        assert_1.default.strictEqual(durationMillisAgain, durationInMilliseconds);
    });
    it('converts to and from milliseconds duration (with decimal places)', function () {
        var durationInMilliseconds = 3.000161;
        var duration = millisecondsToDuration(durationInMilliseconds);
        var durationMillisAgain = durationToMilliseconds(duration);
        assert_1.default.strictEqual(durationMillisAgain, durationInMilliseconds);
    });
    it('adds durations (nanos only)', function () {
        var durationA = millisecondsToDuration(100);
        var durationB = millisecondsToDuration(200);
        var sumDuration = (0, TimeConversion_js_1.addDurations)(durationA, durationB);
        assert_1.default.deepStrictEqual(sumDuration, { seconds: 0, nanos: 3e8 });
    });
    it('adds durations (seconds only)', function () {
        var durationA = millisecondsToDuration(1000);
        var durationB = millisecondsToDuration(2000);
        var sumDuration = (0, TimeConversion_js_1.addDurations)(durationA, durationB);
        assert_1.default.deepStrictEqual(sumDuration, { seconds: 3, nanos: 0 });
    });
    it('adds durations (seconds and nanos)', function () {
        var durationA = millisecondsToDuration(1500);
        var durationB = millisecondsToDuration(1600);
        var sumDuration = (0, TimeConversion_js_1.addDurations)(durationA, durationB);
        assert_1.default.deepStrictEqual(sumDuration, { seconds: 3, nanos: 1e8 });
    });
    it('adds durations (seconds and nanos) with legacy string seconds', function () {
        var durationA = millisecondsToDuration(1500);
        // @ts-ignore
        durationA.seconds = String(durationA.seconds);
        var durationB = millisecondsToDuration(1600);
        // @ts-ignore
        durationB.seconds = String(durationB.seconds);
        var sumDuration = (0, TimeConversion_js_1.addDurations)(durationA, durationB);
        assert_1.default.deepStrictEqual(sumDuration, { seconds: 3, nanos: 1e8 });
    });
});
//# sourceMappingURL=TimeConversionTest.js.map