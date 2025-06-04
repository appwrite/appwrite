"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var getWorstTestStepResult_js_1 = require("../src/getWorstTestStepResult.js");
var messages_js_1 = require("../src/messages.js");
var assert_1 = __importDefault(require("assert"));
describe('getWorstTestStepResult', function () {
    it('returns a FAILED result for PASSED,FAILED,PASSED', function () {
        var result = (0, getWorstTestStepResult_js_1.getWorstTestStepResult)([
            {
                status: messages_js_1.TestStepResultStatus.PASSED,
                duration: { seconds: 0, nanos: 0 },
            },
            {
                status: messages_js_1.TestStepResultStatus.FAILED,
                duration: { seconds: 0, nanos: 0 },
            },
            {
                status: messages_js_1.TestStepResultStatus.PASSED,
                duration: { seconds: 0, nanos: 0 },
            },
        ]);
        assert_1.default.strictEqual(result.status, messages_js_1.TestStepResultStatus.FAILED);
    });
});
//# sourceMappingURL=getWorstTestStepResultsTest.js.map