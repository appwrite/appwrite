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
exports.parseTestCaseAttempt = void 0;
const keyword_type_1 = require("./keyword_type");
const gherkin_document_parser_1 = require("./gherkin_document_parser");
const pickle_parser_1 = require("./pickle_parser");
const messages = __importStar(require("@cucumber/messages"));
const value_checker_1 = require("../../value_checker");
const messages_1 = require("@cucumber/messages");
function parseStep({ isBeforeHook, gherkinStepMap, keyword, keywordType, pickleStep, pickleUri, snippetBuilder, supportCodeLibrary, testStep, testStepResult, testStepAttachments, }) {
    const out = {
        attachments: testStepAttachments,
        keyword: (0, value_checker_1.doesHaveValue)(testStep.pickleStepId)
            ? keyword
            : isBeforeHook
                ? 'Before'
                : 'After',
        result: testStepResult,
    };
    if ((0, value_checker_1.doesHaveValue)(testStep.hookId)) {
        let hookDefinition;
        if (isBeforeHook) {
            hookDefinition = supportCodeLibrary.beforeTestCaseHookDefinitions.find((x) => x.id === testStep.hookId);
        }
        else {
            hookDefinition = supportCodeLibrary.afterTestCaseHookDefinitions.find((x) => x.id === testStep.hookId);
        }
        out.actionLocation = {
            uri: hookDefinition.uri,
            line: hookDefinition.line,
        };
        out.name = hookDefinition.name;
    }
    if ((0, value_checker_1.doesHaveValue)(testStep.stepDefinitionIds) &&
        testStep.stepDefinitionIds.length === 1) {
        const stepDefinition = supportCodeLibrary.stepDefinitions.find((x) => x.id === testStep.stepDefinitionIds[0]);
        out.actionLocation = {
            uri: stepDefinition.uri,
            line: stepDefinition.line,
        };
    }
    if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId)) {
        out.sourceLocation = {
            uri: pickleUri,
            line: gherkinStepMap[pickleStep.astNodeIds[0]].location.line,
        };
        out.text = pickleStep.text;
        if ((0, value_checker_1.doesHaveValue)(pickleStep.argument)) {
            out.argument = pickleStep.argument;
        }
    }
    if (testStepResult.status === messages.TestStepResultStatus.UNDEFINED) {
        out.snippet = snippetBuilder.build({ keywordType, pickleStep });
    }
    return out;
}
// Converts a testCaseAttempt into a json object with all data needed for
// displaying it in a pretty format
function parseTestCaseAttempt({ testCaseAttempt, snippetBuilder, supportCodeLibrary, }) {
    const { testCase, pickle, gherkinDocument } = testCaseAttempt;
    const gherkinStepMap = (0, gherkin_document_parser_1.getGherkinStepMap)(gherkinDocument);
    const gherkinScenarioLocationMap = (0, gherkin_document_parser_1.getGherkinScenarioLocationMap)(gherkinDocument);
    const pickleStepMap = (0, pickle_parser_1.getPickleStepMap)(pickle);
    const relativePickleUri = pickle.uri;
    const parsedTestCase = {
        attempt: testCaseAttempt.attempt,
        name: pickle.name,
        sourceLocation: {
            uri: relativePickleUri,
            line: gherkinScenarioLocationMap[pickle.astNodeIds[pickle.astNodeIds.length - 1]].line,
        },
        worstTestStepResult: testCaseAttempt.worstTestStepResult,
    };
    const parsedTestSteps = [];
    let isBeforeHook = true;
    let previousKeywordType = keyword_type_1.KeywordType.Precondition;
    testCase.testSteps.forEach((testStep) => {
        const testStepResult = testCaseAttempt.stepResults[testStep.id] || new messages_1.TestStepResult();
        isBeforeHook = isBeforeHook && (0, value_checker_1.doesHaveValue)(testStep.hookId);
        let keyword, keywordType, pickleStep;
        if ((0, value_checker_1.doesHaveValue)(testStep.pickleStepId)) {
            pickleStep = pickleStepMap[testStep.pickleStepId];
            keyword = (0, pickle_parser_1.getStepKeyword)({ pickleStep, gherkinStepMap });
            keywordType = (0, keyword_type_1.getStepKeywordType)({
                keyword,
                language: gherkinDocument.feature.language,
                previousKeywordType,
            });
        }
        const parsedStep = parseStep({
            isBeforeHook,
            gherkinStepMap,
            keyword,
            keywordType,
            pickleStep,
            pickleUri: relativePickleUri,
            snippetBuilder,
            supportCodeLibrary,
            testStep,
            testStepResult,
            testStepAttachments: (0, value_checker_1.valueOrDefault)(testCaseAttempt.stepAttachments[testStep.id], []),
        });
        parsedTestSteps.push(parsedStep);
        previousKeywordType = keywordType;
    });
    return {
        testCase: parsedTestCase,
        testSteps: parsedTestSteps,
    };
}
exports.parseTestCaseAttempt = parseTestCaseAttempt;
//# sourceMappingURL=test_case_attempt_parser.js.map