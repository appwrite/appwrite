import assert from 'assert';
import evaluateVariableExpression from '../src/evaluateVariableExpression.js';
describe('createMeta', () => {
    it('returns undefined when a variable is undefined', () => {
        const expression = 'hello-${SOME_VAR}';
        const result = evaluateVariableExpression(expression, {});
        assert.strictEqual(result, undefined);
    });
    it('gets a value without replacement', () => {
        const expression = '${SOME_VAR}';
        const result = evaluateVariableExpression(expression, { SOME_VAR: 'some_value' });
        assert.strictEqual(result, 'some_value');
    });
    it('captures a group', () => {
        const expression = '${SOME_REF/refs\\/heads\\/(.*)/\\1}';
        const result = evaluateVariableExpression(expression, { SOME_REF: 'refs/heads/main' });
        assert.strictEqual(result, 'main');
    });
    it('works with star wildcard in var', () => {
        const expression = '${GO_SCM_*_PR_BRANCH/.*:(.*)/\\1}';
        const result = evaluateVariableExpression(expression, {
            GO_SCM_MY_MATERIAL_PR_BRANCH: 'ashwankthkumar:feature-1',
        });
        assert.strictEqual(result, 'feature-1');
    });
    it('evaluates a complex expression', () => {
        const expression = 'hello-${VAR1}-${VAR2/(.*) (.*)/\\2-\\1}-world';
        const result = evaluateVariableExpression(expression, {
            VAR1: 'amazing',
            VAR2: 'gorgeous beautiful',
        });
        assert.strictEqual(result, 'hello-amazing-beautiful-gorgeous-world');
    });
});
//# sourceMappingURL=evaluateVariableExpressionTest.js.map