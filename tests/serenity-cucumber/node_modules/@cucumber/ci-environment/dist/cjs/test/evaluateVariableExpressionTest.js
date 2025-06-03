"use strict";
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
var assert_1 = __importDefault(require("assert"));
var evaluateVariableExpression_js_1 = __importDefault(require("../src/evaluateVariableExpression.js"));
describe('createMeta', function () {
    it('returns undefined when a variable is undefined', function () {
        var expression = 'hello-${SOME_VAR}';
        var result = (0, evaluateVariableExpression_js_1.default)(expression, {});
        assert_1.default.strictEqual(result, undefined);
    });
    it('gets a value without replacement', function () {
        var expression = '${SOME_VAR}';
        var result = (0, evaluateVariableExpression_js_1.default)(expression, { SOME_VAR: 'some_value' });
        assert_1.default.strictEqual(result, 'some_value');
    });
    it('captures a group', function () {
        var expression = '${SOME_REF/refs\\/heads\\/(.*)/\\1}';
        var result = (0, evaluateVariableExpression_js_1.default)(expression, { SOME_REF: 'refs/heads/main' });
        assert_1.default.strictEqual(result, 'main');
    });
    it('works with star wildcard in var', function () {
        var expression = '${GO_SCM_*_PR_BRANCH/.*:(.*)/\\1}';
        var result = (0, evaluateVariableExpression_js_1.default)(expression, {
            GO_SCM_MY_MATERIAL_PR_BRANCH: 'ashwankthkumar:feature-1',
        });
        assert_1.default.strictEqual(result, 'feature-1');
    });
    it('evaluates a complex expression', function () {
        var expression = 'hello-${VAR1}-${VAR2/(.*) (.*)/\\2-\\1}-world';
        var result = (0, evaluateVariableExpression_js_1.default)(expression, {
            VAR1: 'amazing',
            VAR2: 'gorgeous beautiful',
        });
        assert_1.default.strictEqual(result, 'hello-amazing-beautiful-gorgeous-world');
    });
});
//# sourceMappingURL=evaluateVariableExpressionTest.js.map