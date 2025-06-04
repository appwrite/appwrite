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
exports.formatTestCaseAttempt = void 0;
const indent_string_1 = __importDefault(require("indent-string"));
const messages = __importStar(require("@cucumber/messages"));
const figures_1 = __importDefault(require("figures"));
const location_helpers_1 = require("./location_helpers");
const test_case_attempt_parser_1 = require("./test_case_attempt_parser");
const step_argument_formatter_1 = require("./step_argument_formatter");
const value_checker_1 = require("../../value_checker");
const CHARACTERS = new Map([
    [messages.TestStepResultStatus.AMBIGUOUS, figures_1.default.cross],
    [messages.TestStepResultStatus.FAILED, figures_1.default.cross],
    [messages.TestStepResultStatus.PASSED, figures_1.default.tick],
    [messages.TestStepResultStatus.PENDING, '?'],
    [messages.TestStepResultStatus.SKIPPED, '-'],
    [messages.TestStepResultStatus.UNDEFINED, '?'],
]);
function getStepMessage(testStep) {
    switch (testStep.result.status) {
        case messages.TestStepResultStatus.AMBIGUOUS:
        case messages.TestStepResultStatus.FAILED:
            return testStep.result.message;
        case messages.TestStepResultStatus.UNDEFINED:
            return `${'Undefined. Implement with the following snippet:' + '\n\n'}${(0, indent_string_1.default)(testStep.snippet, 2)}\n`;
        case messages.TestStepResultStatus.PENDING:
            return 'Pending';
    }
    return '';
}
function formatStep({ colorFns, testStep, printAttachments, }) {
    const { name, result: { status }, actionLocation, attachments, } = testStep;
    const colorFn = colorFns.forStatus(status);
    const identifier = testStep.keyword + (0, value_checker_1.valueOrDefault)(testStep.text, '');
    let text = colorFn(`${CHARACTERS.get(status)} ${identifier}`);
    if ((0, value_checker_1.doesHaveValue)(name)) {
        text += colorFn(` (${name})`);
    }
    if ((0, value_checker_1.doesHaveValue)(actionLocation)) {
        text += ` # ${colorFns.location((0, location_helpers_1.formatLocation)(actionLocation))}`;
    }
    text += '\n';
    if ((0, value_checker_1.doesHaveValue)(testStep.argument)) {
        const argumentsText = (0, step_argument_formatter_1.formatStepArgument)(testStep.argument);
        text += (0, indent_string_1.default)(`${colorFn(argumentsText)}\n`, 4);
    }
    if ((0, value_checker_1.valueOrDefault)(printAttachments, true)) {
        attachments.forEach(({ body, mediaType, fileName }) => {
            let message = '';
            if (mediaType === 'text/plain') {
                message = `: ${body}`;
            }
            else if (fileName) {
                message = `: ${fileName}`;
            }
            text += (0, indent_string_1.default)(`Attachment (${mediaType})${message}\n`, 4);
        });
    }
    const message = getStepMessage(testStep);
    if (message !== '') {
        text += `${(0, indent_string_1.default)(colorFn(message), 4)}\n`;
    }
    return text;
}
function formatTestCaseAttempt({ colorFns, snippetBuilder, supportCodeLibrary, testCaseAttempt, printAttachments, }) {
    const parsed = (0, test_case_attempt_parser_1.parseTestCaseAttempt)({
        snippetBuilder,
        testCaseAttempt,
        supportCodeLibrary,
    });
    let text = `Scenario: ${parsed.testCase.name}`;
    text += getAttemptText(parsed.testCase.attempt, testCaseAttempt.willBeRetried);
    text += ` # ${colorFns.location((0, location_helpers_1.formatLocation)(parsed.testCase.sourceLocation))}\n`;
    parsed.testSteps.forEach((testStep) => {
        text += formatStep({ colorFns, testStep, printAttachments });
    });
    return `${text}\n`;
}
exports.formatTestCaseAttempt = formatTestCaseAttempt;
function getAttemptText(attempt, willBeRetried) {
    if (attempt > 0 || willBeRetried) {
        const numberStr = (attempt + 1).toString();
        const retriedStr = willBeRetried ? ', retried' : '';
        return ` (attempt ${numberStr}${retriedStr})`;
    }
    return '';
}
//# sourceMappingURL=test_case_attempt_formatter.js.map