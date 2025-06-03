"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const helpers_1 = require("./helpers");
const _1 = __importDefault(require("./"));
const value_checker_1 = require("../value_checker");
const issue_helpers_1 = require("./helpers/issue_helpers");
const time_1 = require("../time");
class SummaryFormatter extends _1.default {
    constructor(options) {
        super(options);
        let testRunStartedTimestamp;
        options.eventBroadcaster.on('envelope', (envelope) => {
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunStarted)) {
                testRunStartedTimestamp = envelope.testRunStarted.timestamp;
            }
            if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
                const testRunFinishedTimestamp = envelope.testRunFinished.timestamp;
                this.logSummary((0, time_1.durationBetweenTimestamps)(testRunStartedTimestamp, testRunFinishedTimestamp));
            }
        });
    }
    logSummary(testRunDuration) {
        const failures = [];
        const warnings = [];
        const testCaseAttempts = this.eventDataCollector.getTestCaseAttempts();
        testCaseAttempts.forEach((testCaseAttempt) => {
            if ((0, helpers_1.isFailure)(testCaseAttempt.worstTestStepResult, testCaseAttempt.willBeRetried)) {
                failures.push(testCaseAttempt);
            }
            else if ((0, helpers_1.isWarning)(testCaseAttempt.worstTestStepResult, testCaseAttempt.willBeRetried)) {
                warnings.push(testCaseAttempt);
            }
        });
        if (this.eventDataCollector.undefinedParameterTypes.length > 0) {
            this.log((0, issue_helpers_1.formatUndefinedParameterTypes)(this.eventDataCollector.undefinedParameterTypes));
        }
        if (failures.length > 0) {
            this.logIssues({ issues: failures, title: 'Failures' });
        }
        if (warnings.length > 0) {
            this.logIssues({ issues: warnings, title: 'Warnings' });
        }
        this.log((0, helpers_1.formatSummary)({
            colorFns: this.colorFns,
            testCaseAttempts,
            testRunDuration,
        }));
    }
    logIssues({ issues, title }) {
        this.log(`${title}:\n\n`);
        issues.forEach((testCaseAttempt, index) => {
            this.log((0, helpers_1.formatIssue)({
                colorFns: this.colorFns,
                number: index + 1,
                snippetBuilder: this.snippetBuilder,
                supportCodeLibrary: this.supportCodeLibrary,
                testCaseAttempt,
                printAttachments: this.printAttachments,
            }));
        });
    }
}
exports.default = SummaryFormatter;
SummaryFormatter.documentation = 'Summary output of feature and scenarios';
//# sourceMappingURL=summary_formatter.js.map