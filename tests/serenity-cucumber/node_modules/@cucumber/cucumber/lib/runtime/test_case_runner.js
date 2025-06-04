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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const helpers_1 = require("./helpers");
const attachment_manager_1 = __importDefault(require("./attachment_manager"));
const step_runner_1 = __importDefault(require("./step_runner"));
const messages = __importStar(require("@cucumber/messages"));
const messages_1 = require("@cucumber/messages");
const value_checker_1 = require("../value_checker");
class TestCaseRunner {
    constructor({ eventBroadcaster, stopwatch, gherkinDocument, newId, pickle, testCase, retries = 0, skip, filterStackTraces, supportCodeLibrary, worldParameters, }) {
        this.attachmentManager = new attachment_manager_1.default(({ data, media, fileName }) => {
            if ((0, value_checker_1.doesNotHaveValue)(this.currentTestStepId)) {
                throw new Error('Cannot attach when a step/hook is not running. Ensure your step/hook waits for the attach to finish.');
            }
            const attachment = {
                attachment: {
                    body: data,
                    contentEncoding: media.encoding,
                    mediaType: media.contentType,
                    fileName,
                    testCaseStartedId: this.currentTestCaseStartedId,
                    testStepId: this.currentTestStepId,
                },
            };
            this.eventBroadcaster.emit('envelope', attachment);
        });
        this.eventBroadcaster = eventBroadcaster;
        this.stopwatch = stopwatch;
        this.gherkinDocument = gherkinDocument;
        this.maxAttempts = 1 + (skip ? 0 : retries);
        this.newId = newId;
        this.pickle = pickle;
        this.testCase = testCase;
        this.skip = skip;
        this.filterStackTraces = filterStackTraces;
        this.supportCodeLibrary = supportCodeLibrary;
        this.worldParameters = worldParameters;
        this.resetTestProgressData();
    }
    resetTestProgressData() {
        this.world = new this.supportCodeLibrary.World({
            attach: this.attachmentManager.create.bind(this.attachmentManager),
            log: this.attachmentManager.log.bind(this.attachmentManager),
            parameters: this.worldParameters,
        });
        this.testStepResults = [];
    }
    getBeforeStepHookDefinitions() {
        return this.supportCodeLibrary.beforeTestStepHookDefinitions.filter((hookDefinition) => hookDefinition.appliesToTestCase(this.pickle));
    }
    getAfterStepHookDefinitions() {
        return this.supportCodeLibrary.afterTestStepHookDefinitions
            .slice(0)
            .reverse()
            .filter((hookDefinition) => hookDefinition.appliesToTestCase(this.pickle));
    }
    getWorstStepResult() {
        if (this.testStepResults.length === 0) {
            return {
                status: this.skip
                    ? messages.TestStepResultStatus.SKIPPED
                    : messages.TestStepResultStatus.PASSED,
                duration: messages.TimeConversion.millisecondsToDuration(0),
            };
        }
        return (0, messages_1.getWorstTestStepResult)(this.testStepResults);
    }
    async invokeStep(step, stepDefinition, hookParameter) {
        return await step_runner_1.default.run({
            defaultTimeout: this.supportCodeLibrary.defaultTimeout,
            filterStackTraces: this.filterStackTraces,
            hookParameter,
            step,
            stepDefinition,
            world: this.world,
        });
    }
    isSkippingSteps() {
        return (this.getWorstStepResult().status !== messages.TestStepResultStatus.PASSED);
    }
    shouldSkipHook(isBeforeHook) {
        return this.skip || (this.isSkippingSteps() && isBeforeHook);
    }
    async aroundTestStep(testStepId, runStepFn) {
        const testStepStarted = {
            testStepStarted: {
                testCaseStartedId: this.currentTestCaseStartedId,
                testStepId,
                timestamp: this.stopwatch.timestamp(),
            },
        };
        this.eventBroadcaster.emit('envelope', testStepStarted);
        this.currentTestStepId = testStepId;
        const testStepResult = await runStepFn();
        this.currentTestStepId = null;
        this.testStepResults.push(testStepResult);
        const testStepFinished = {
            testStepFinished: {
                testCaseStartedId: this.currentTestCaseStartedId,
                testStepId,
                testStepResult,
                timestamp: this.stopwatch.timestamp(),
            },
        };
        this.eventBroadcaster.emit('envelope', testStepFinished);
    }
    async run() {
        for (let attempt = 0; attempt < this.maxAttempts; attempt++) {
            const moreAttemptsRemaining = attempt + 1 < this.maxAttempts;
            const willBeRetried = await this.runAttempt(attempt, moreAttemptsRemaining);
            if (!willBeRetried) {
                break;
            }
            this.resetTestProgressData();
        }
        return this.getWorstStepResult().status;
    }
    async runAttempt(attempt, moreAttemptsRemaining) {
        this.currentTestCaseStartedId = this.newId();
        const testCaseStarted = {
            testCaseStarted: {
                attempt,
                testCaseId: this.testCase.id,
                id: this.currentTestCaseStartedId,
                timestamp: this.stopwatch.timestamp(),
            },
        };
        this.eventBroadcaster.emit('envelope', testCaseStarted);
        // used to determine whether a hook is a Before or After
        let didWeRunStepsYet = false;
        for (const testStep of this.testCase.testSteps) {
            await this.aroundTestStep(testStep.id, async () => {
                if ((0, value_checker_1.doesHaveValue)(testStep.hookId)) {
                    const hookParameter = {
                        gherkinDocument: this.gherkinDocument,
                        pickle: this.pickle,
                        testCaseStartedId: this.currentTestCaseStartedId,
                    };
                    if (didWeRunStepsYet) {
                        hookParameter.result = this.getWorstStepResult();
                        hookParameter.willBeRetried =
                            this.getWorstStepResult().status ===
                                messages.TestStepResultStatus.FAILED && moreAttemptsRemaining;
                    }
                    return await this.runHook(findHookDefinition(testStep.hookId, this.supportCodeLibrary), hookParameter, !didWeRunStepsYet);
                }
                else {
                    const pickleStep = this.pickle.steps.find((pickleStep) => pickleStep.id === testStep.pickleStepId);
                    const testStepResult = await this.runStep(pickleStep, testStep);
                    didWeRunStepsYet = true;
                    return testStepResult;
                }
            });
        }
        const willBeRetried = this.getWorstStepResult().status ===
            messages.TestStepResultStatus.FAILED && moreAttemptsRemaining;
        const testCaseFinished = {
            testCaseFinished: {
                testCaseStartedId: this.currentTestCaseStartedId,
                timestamp: this.stopwatch.timestamp(),
                willBeRetried,
            },
        };
        this.eventBroadcaster.emit('envelope', testCaseFinished);
        return willBeRetried;
    }
    async runHook(hookDefinition, hookParameter, isBeforeHook) {
        if (this.shouldSkipHook(isBeforeHook)) {
            return {
                status: messages.TestStepResultStatus.SKIPPED,
                duration: messages.TimeConversion.millisecondsToDuration(0),
            };
        }
        return await this.invokeStep(null, hookDefinition, hookParameter);
    }
    async runStepHooks(stepHooks, pickleStep, stepResult) {
        const stepHooksResult = [];
        const hookParameter = {
            gherkinDocument: this.gherkinDocument,
            pickle: this.pickle,
            pickleStep,
            testCaseStartedId: this.currentTestCaseStartedId,
            testStepId: this.currentTestStepId,
            result: stepResult,
        };
        for (const stepHookDefinition of stepHooks) {
            stepHooksResult.push(await this.invokeStep(null, stepHookDefinition, hookParameter));
        }
        return stepHooksResult;
    }
    async runStep(pickleStep, testStep) {
        const stepDefinitions = testStep.stepDefinitionIds.map((stepDefinitionId) => {
            return findStepDefinition(stepDefinitionId, this.supportCodeLibrary);
        });
        if (stepDefinitions.length === 0) {
            return {
                status: messages.TestStepResultStatus.UNDEFINED,
                duration: messages.TimeConversion.millisecondsToDuration(0),
            };
        }
        else if (stepDefinitions.length > 1) {
            return {
                message: (0, helpers_1.getAmbiguousStepException)(stepDefinitions),
                status: messages.TestStepResultStatus.AMBIGUOUS,
                duration: messages.TimeConversion.millisecondsToDuration(0),
            };
        }
        else if (this.isSkippingSteps()) {
            return {
                status: messages.TestStepResultStatus.SKIPPED,
                duration: messages.TimeConversion.millisecondsToDuration(0),
            };
        }
        let stepResult;
        let stepResults = await this.runStepHooks(this.getBeforeStepHookDefinitions(), pickleStep);
        if ((0, messages_1.getWorstTestStepResult)(stepResults).status !==
            messages.TestStepResultStatus.FAILED) {
            stepResult = await this.invokeStep(pickleStep, stepDefinitions[0]);
            stepResults.push(stepResult);
        }
        const afterStepHookResults = await this.runStepHooks(this.getAfterStepHookDefinitions(), pickleStep, stepResult);
        stepResults = stepResults.concat(afterStepHookResults);
        const finalStepResult = (0, messages_1.getWorstTestStepResult)(stepResults);
        let finalDuration = messages.TimeConversion.millisecondsToDuration(0);
        for (const result of stepResults) {
            finalDuration = messages.TimeConversion.addDurations(finalDuration, result.duration);
        }
        finalStepResult.duration = finalDuration;
        return finalStepResult;
    }
}
exports.default = TestCaseRunner;
function findHookDefinition(id, supportCodeLibrary) {
    return [
        ...supportCodeLibrary.beforeTestCaseHookDefinitions,
        ...supportCodeLibrary.afterTestCaseHookDefinitions,
    ].find((definition) => definition.id === id);
}
function findStepDefinition(id, supportCodeLibrary) {
    return supportCodeLibrary.stepDefinitions.find((definition) => definition.id === id);
}
//# sourceMappingURL=test_case_runner.js.map