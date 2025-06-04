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
exports.getUsage = void 0;
const pickle_parser_1 = require("../pickle_parser");
const gherkin_document_parser_1 = require("../gherkin_document_parser");
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../../value_checker");
function buildEmptyMapping(stepDefinitions) {
    const mapping = {};
    stepDefinitions.forEach((stepDefinition) => {
        mapping[stepDefinition.id] = {
            code: stepDefinition.unwrappedCode.toString(),
            line: stepDefinition.line,
            pattern: stepDefinition.expression.source,
            patternType: stepDefinition.expression.constructor.name,
            matches: [],
            uri: stepDefinition.uri,
        };
    });
    return mapping;
}
const unexecutedStatuses = [
    messages.TestStepResultStatus.AMBIGUOUS,
    messages.TestStepResultStatus.SKIPPED,
    messages.TestStepResultStatus.UNDEFINED,
];
function buildMapping({ stepDefinitions, eventDataCollector, }) {
    const mapping = buildEmptyMapping(stepDefinitions);
    eventDataCollector.getTestCaseAttempts().forEach((testCaseAttempt) => {
        const pickleStepMap = (0, pickle_parser_1.getPickleStepMap)(testCaseAttempt.pickle);
        const gherkinStepMap = (0, gherkin_document_parser_1.getGherkinStepMap)(testCaseAttempt.gherkinDocument);
        testCaseAttempt.testCase.testSteps.forEach((testStep) => {
            if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId) &&
                testStep.stepDefinitionIds.length === 1) {
                const stepDefinitionId = testStep.stepDefinitionIds[0];
                const pickleStep = pickleStepMap[testStep.pickleStepId];
                const gherkinStep = gherkinStepMap[pickleStep.astNodeIds[0]];
                const match = {
                    line: gherkinStep.location.line,
                    text: pickleStep.text,
                    uri: testCaseAttempt.pickle.uri,
                };
                const { duration, status } = testCaseAttempt.stepResults[testStep.id];
                if (!unexecutedStatuses.includes(status) && (0, value_checker_1.doesHaveValue)(duration)) {
                    match.duration = duration;
                }
                if ((0, value_checker_1.doesHaveValue)(mapping[stepDefinitionId])) {
                    mapping[stepDefinitionId].matches.push(match);
                }
            }
        });
    });
    return mapping;
}
function normalizeDuration(duration) {
    if (duration == null) {
        return Number.MIN_SAFE_INTEGER;
    }
    return messages.TimeConversion.durationToMilliseconds(duration);
}
function buildResult(mapping) {
    return Object.keys(mapping)
        .map((stepDefinitionId) => {
        const { matches, ...rest } = mapping[stepDefinitionId];
        const sortedMatches = matches.sort((a, b) => {
            if (a.duration === b.duration) {
                return a.text < b.text ? -1 : 1;
            }
            return normalizeDuration(b.duration) - normalizeDuration(a.duration);
        });
        const result = { matches: sortedMatches, ...rest };
        const durations = matches
            .filter((m) => m.duration != null)
            .map((m) => m.duration);
        if (durations.length > 0) {
            const totalMilliseconds = durations.reduce((acc, x) => acc + messages.TimeConversion.durationToMilliseconds(x), 0);
            result.meanDuration = messages.TimeConversion.millisecondsToDuration(totalMilliseconds / durations.length);
        }
        return result;
    })
        .sort((a, b) => normalizeDuration(b.meanDuration) - normalizeDuration(a.meanDuration));
}
function getUsage({ stepDefinitions, eventDataCollector, }) {
    const mapping = buildMapping({ stepDefinitions, eventDataCollector });
    return buildResult(mapping);
}
exports.getUsage = getUsage;
//# sourceMappingURL=index.js.map