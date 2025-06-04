"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.formatUndefinedParameterType = exports.formatUndefinedParameterTypes = exports.formatIssue = exports.isIssue = exports.isWarning = exports.isFailure = void 0;
const indent_string_1 = __importDefault(require("indent-string"));
const test_case_attempt_formatter_1 = require("./test_case_attempt_formatter");
function isFailure(result, willBeRetried = false) {
    return (result.status === 'AMBIGUOUS' ||
        result.status === 'UNDEFINED' ||
        (result.status === 'FAILED' && !willBeRetried));
}
exports.isFailure = isFailure;
function isWarning(result, willBeRetried = false) {
    return (result.status === 'PENDING' || (result.status === 'FAILED' && willBeRetried));
}
exports.isWarning = isWarning;
function isIssue(result) {
    return isFailure(result) || isWarning(result);
}
exports.isIssue = isIssue;
function formatIssue({ colorFns, number, snippetBuilder, testCaseAttempt, supportCodeLibrary, printAttachments = true, }) {
    const prefix = `${number.toString()}) `;
    const formattedTestCaseAttempt = (0, test_case_attempt_formatter_1.formatTestCaseAttempt)({
        colorFns,
        snippetBuilder,
        testCaseAttempt,
        supportCodeLibrary,
        printAttachments,
    });
    const lines = formattedTestCaseAttempt.split('\n');
    const updatedLines = lines.map((line, index) => {
        if (index === 0) {
            return `${prefix}${line}`;
        }
        return (0, indent_string_1.default)(line, prefix.length);
    });
    return updatedLines.join('\n');
}
exports.formatIssue = formatIssue;
function formatUndefinedParameterTypes(undefinedParameterTypes) {
    const output = [`Undefined parameter types:\n\n`];
    const withLatest = {};
    undefinedParameterTypes.forEach((parameterType) => {
        withLatest[parameterType.name] = parameterType;
    });
    output.push(Object.values(withLatest)
        .map((parameterType) => `- ${formatUndefinedParameterType(parameterType)}`)
        .join('\n'));
    output.push('\n\n');
    return output.join('');
}
exports.formatUndefinedParameterTypes = formatUndefinedParameterTypes;
function formatUndefinedParameterType(parameterType) {
    return `"${parameterType.name}" e.g. \`${parameterType.expression}\``;
}
exports.formatUndefinedParameterType = formatUndefinedParameterType;
//# sourceMappingURL=issue_helpers.js.map