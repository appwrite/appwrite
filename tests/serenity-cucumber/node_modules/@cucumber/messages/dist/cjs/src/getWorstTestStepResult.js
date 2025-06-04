"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.getWorstTestStepResult = void 0;
var messages_js_1 = require("./messages.js");
var TimeConversion_js_1 = require("./TimeConversion.js");
/**
 * Gets the worst result
 * @param testStepResults
 */
function getWorstTestStepResult(testStepResults) {
    return (testStepResults.slice().sort(function (r1, r2) { return ordinal(r2.status) - ordinal(r1.status); })[0] || {
        status: messages_js_1.TestStepResultStatus.UNKNOWN,
        duration: (0, TimeConversion_js_1.millisecondsToDuration)(0),
    });
}
exports.getWorstTestStepResult = getWorstTestStepResult;
function ordinal(status) {
    return [
        messages_js_1.TestStepResultStatus.UNKNOWN,
        messages_js_1.TestStepResultStatus.PASSED,
        messages_js_1.TestStepResultStatus.SKIPPED,
        messages_js_1.TestStepResultStatus.PENDING,
        messages_js_1.TestStepResultStatus.UNDEFINED,
        messages_js_1.TestStepResultStatus.AMBIGUOUS,
        messages_js_1.TestStepResultStatus.FAILED,
    ].indexOf(status);
}
//# sourceMappingURL=getWorstTestStepResult.js.map