"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const helpers_1 = require("./helpers");
const _1 = __importDefault(require("./"));
const progress_1 = __importDefault(require("progress"));
const value_checker_1 = require("../value_checker");
const issue_helpers_1 = require("./helpers/issue_helpers");
const time_1 = require("../time");
// Inspired by https://github.com/thekompanee/fuubar and https://github.com/martinciu/fuubar-cucumber
class ProgressBarFormatter extends _1.default {
    constructor(options) {
        super(options);
        options.eventBroadcaster.on('envelope', this.parseEnvelope.bind(this));
        this.numberOfSteps = 0;
        this.issueCount = 0;
    }
    incrementStepCount(pickleId) {
        const pickle = this.eventDataCollector.getPickle(pickleId);
        this.numberOfSteps += pickle.steps.length;
    }
    initializeProgressBar() {
        if ((0, value_checker_1.doesHaveValue)(this.progressBar)) {
            return;
        }
        this.progressBar = new progress_1.default(':current/:total steps [:bar] ', {
            clear: true,
            incomplete: ' ',
            stream: this.stream,
            total: this.numberOfSteps,
            width: (0, value_checker_1.valueOrDefault)(this.stream.columns, 80),
        });
    }
    logProgress({ testStepId, testCaseStartedId, }) {
        const { testCase } = this.eventDataCollector.getTestCaseAttempt(testCaseStartedId);
        const testStep = testCase.testSteps.find((s) => s.id === testStepId);
        if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId)) {
            this.progressBar.tick();
        }
    }
    logUndefinedParametertype(parameterType) {
        this.log(`Undefined parameter type: ${(0, issue_helpers_1.formatUndefinedParameterType)(parameterType)}\n`);
    }
    logErrorIfNeeded(testCaseFinished) {
        const { worstTestStepResult } = this.eventDataCollector.getTestCaseAttempt(testCaseFinished.testCaseStartedId);
        if ((0, helpers_1.isIssue)(worstTestStepResult)) {
            this.issueCount += 1;
            const testCaseAttempt = this.eventDataCollector.getTestCaseAttempt(testCaseFinished.testCaseStartedId);
            this.progressBar.interrupt((0, helpers_1.formatIssue)({
                colorFns: this.colorFns,
                number: this.issueCount,
                snippetBuilder: this.snippetBuilder,
                supportCodeLibrary: this.supportCodeLibrary,
                testCaseAttempt,
                printAttachments: this.printAttachments,
            }));
            if (testCaseFinished.willBeRetried) {
                const stepsToRetry = testCaseAttempt.pickle.steps.length;
                this.progressBar.tick(-stepsToRetry);
            }
        }
    }
    logSummary(testRunFinished) {
        const testRunDuration = (0, time_1.durationBetweenTimestamps)(this.testRunStarted.timestamp, testRunFinished.timestamp);
        this.log((0, helpers_1.formatSummary)({
            colorFns: this.colorFns,
            testCaseAttempts: this.eventDataCollector.getTestCaseAttempts(),
            testRunDuration,
        }));
    }
    parseEnvelope(envelope) {
        if ((0, value_checker_1.doesHaveValue)(envelope.undefinedParameterType)) {
            this.logUndefinedParametertype(envelope.undefinedParameterType);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testCase)) {
            this.incrementStepCount(envelope.testCase.pickleId);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testStepStarted)) {
            this.initializeProgressBar();
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testStepFinished)) {
            this.logProgress(envelope.testStepFinished);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testCaseFinished)) {
            this.logErrorIfNeeded(envelope.testCaseFinished);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testRunStarted)) {
            this.testRunStarted = envelope.testRunStarted;
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testRunFinished)) {
            this.logSummary(envelope.testRunFinished);
        }
    }
}
exports.default = ProgressBarFormatter;
ProgressBarFormatter.documentation = 'Similar to the Progress Formatter, but provides a real-time updating progress bar based on the total number of steps to be executed in the test run';
//# sourceMappingURL=progress_bar_formatter.js.map