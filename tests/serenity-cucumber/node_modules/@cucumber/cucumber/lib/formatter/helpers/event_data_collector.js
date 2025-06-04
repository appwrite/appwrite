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
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../value_checker");
class EventDataCollector {
    constructor(eventBroadcaster) {
        this.gherkinDocumentMap = {};
        this.pickleMap = {};
        this.testCaseMap = {};
        this.testCaseAttemptDataMap = {};
        this.undefinedParameterTypes = [];
        eventBroadcaster.on('envelope', this.parseEnvelope.bind(this));
    }
    getGherkinDocument(uri) {
        return this.gherkinDocumentMap[uri];
    }
    getPickle(pickleId) {
        return this.pickleMap[pickleId];
    }
    getTestCaseAttempts() {
        return Object.keys(this.testCaseAttemptDataMap).map((testCaseStartedId) => {
            return this.getTestCaseAttempt(testCaseStartedId);
        });
    }
    getTestCaseAttempt(testCaseStartedId) {
        const testCaseAttemptData = this.testCaseAttemptDataMap[testCaseStartedId];
        const testCase = this.testCaseMap[testCaseAttemptData.testCaseId];
        const pickle = this.pickleMap[testCase.pickleId];
        return {
            gherkinDocument: this.gherkinDocumentMap[pickle.uri],
            pickle,
            testCase,
            attempt: testCaseAttemptData.attempt,
            willBeRetried: testCaseAttemptData.willBeRetried,
            stepAttachments: testCaseAttemptData.stepAttachments,
            stepResults: testCaseAttemptData.stepResults,
            worstTestStepResult: testCaseAttemptData.worstTestStepResult,
        };
    }
    parseEnvelope(envelope) {
        if ((0, value_checker_1.doesHaveValue)(envelope.gherkinDocument)) {
            this.gherkinDocumentMap[envelope.gherkinDocument.uri] =
                envelope.gherkinDocument;
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.pickle)) {
            this.pickleMap[envelope.pickle.id] = envelope.pickle;
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.undefinedParameterType)) {
            this.undefinedParameterTypes.push(envelope.undefinedParameterType);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testCase)) {
            this.testCaseMap[envelope.testCase.id] = envelope.testCase;
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testCaseStarted)) {
            this.initTestCaseAttempt(envelope.testCaseStarted);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.attachment)) {
            this.storeAttachment(envelope.attachment);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testStepFinished)) {
            this.storeTestStepResult(envelope.testStepFinished);
        }
        else if ((0, value_checker_1.doesHaveValue)(envelope.testCaseFinished)) {
            this.storeTestCaseResult(envelope.testCaseFinished);
        }
    }
    initTestCaseAttempt(testCaseStarted) {
        this.testCaseAttemptDataMap[testCaseStarted.id] = {
            attempt: testCaseStarted.attempt,
            willBeRetried: false,
            testCaseId: testCaseStarted.testCaseId,
            stepAttachments: {},
            stepResults: {},
            worstTestStepResult: {
                duration: { seconds: 0, nanos: 0 },
                status: messages.TestStepResultStatus.UNKNOWN,
            },
        };
    }
    storeAttachment(attachment) {
        const { testCaseStartedId, testStepId } = attachment;
        // TODO: we shouldn't have to check if these properties have values - they are non-nullable
        if ((0, value_checker_1.doesHaveValue)(testCaseStartedId) && (0, value_checker_1.doesHaveValue)(testStepId)) {
            const { stepAttachments } = this.testCaseAttemptDataMap[testCaseStartedId];
            if ((0, value_checker_1.doesNotHaveValue)(stepAttachments[testStepId])) {
                stepAttachments[testStepId] = [];
            }
            stepAttachments[testStepId].push(attachment);
        }
    }
    storeTestStepResult({ testCaseStartedId, testStepId, testStepResult, }) {
        this.testCaseAttemptDataMap[testCaseStartedId].stepResults[testStepId] =
            testStepResult;
    }
    storeTestCaseResult({ testCaseStartedId, willBeRetried, }) {
        const stepResults = Object.values(this.testCaseAttemptDataMap[testCaseStartedId].stepResults);
        this.testCaseAttemptDataMap[testCaseStartedId].worstTestStepResult =
            messages.getWorstTestStepResult(stepResults);
        this.testCaseAttemptDataMap[testCaseStartedId].willBeRetried = willBeRetried;
    }
}
exports.default = EventDataCollector;
//# sourceMappingURL=event_data_collector.js.map