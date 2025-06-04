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
exports.formatSummary = void 0;
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../value_checker");
const luxon_1 = require("luxon");
const STATUS_REPORT_ORDER = [
    messages.TestStepResultStatus.FAILED,
    messages.TestStepResultStatus.AMBIGUOUS,
    messages.TestStepResultStatus.UNDEFINED,
    messages.TestStepResultStatus.PENDING,
    messages.TestStepResultStatus.SKIPPED,
    messages.TestStepResultStatus.PASSED,
];
function formatSummary({ colorFns, testCaseAttempts, testRunDuration, }) {
    const testCaseResults = [];
    const testStepResults = [];
    let totalStepDuration = messages.TimeConversion.millisecondsToDuration(0);
    testCaseAttempts.forEach(({ testCase, willBeRetried, worstTestStepResult, stepResults }) => {
        Object.values(stepResults).forEach((stepResult) => {
            totalStepDuration = messages.TimeConversion.addDurations(totalStepDuration, stepResult.duration);
        });
        if (!willBeRetried) {
            testCaseResults.push(worstTestStepResult);
            testCase.testSteps.forEach((testStep) => {
                if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId)) {
                    testStepResults.push(stepResults[testStep.id]);
                }
            });
        }
    });
    const scenarioSummary = getCountSummary({
        colorFns,
        objects: testCaseResults,
        type: 'scenario',
    });
    const stepSummary = getCountSummary({
        colorFns,
        objects: testStepResults,
        type: 'step',
    });
    const durationSummary = `${getDurationSummary(testRunDuration)} (executing steps: ${getDurationSummary(totalStepDuration)})\n`;
    return [scenarioSummary, stepSummary, durationSummary].join('\n');
}
exports.formatSummary = formatSummary;
function getCountSummary({ colorFns, objects, type, }) {
    const counts = {};
    STATUS_REPORT_ORDER.forEach((x) => (counts[x] = 0));
    objects.forEach((x) => (counts[x.status] += 1));
    const total = Object.values(counts).reduce((acc, x) => acc + x, 0);
    let text = `${total.toString()} ${type}${total === 1 ? '' : 's'}`;
    if (total > 0) {
        const details = [];
        STATUS_REPORT_ORDER.forEach((status) => {
            if (counts[status] > 0) {
                details.push(colorFns.forStatus(status)(`${counts[status].toString()} ${status.toLowerCase()}`));
            }
        });
        text += ` (${details.join(', ')})`;
    }
    return text;
}
function getDurationSummary(durationMsg) {
    const start = new Date(0);
    const end = new Date(messages.TimeConversion.durationToMilliseconds(durationMsg));
    const duration = luxon_1.Interval.fromDateTimes(start, end).toDuration([
        'minutes',
        'seconds',
        'milliseconds',
    ]);
    return duration.toFormat("m'm'ss.SSS's'");
}
//# sourceMappingURL=summary_helpers.js.map