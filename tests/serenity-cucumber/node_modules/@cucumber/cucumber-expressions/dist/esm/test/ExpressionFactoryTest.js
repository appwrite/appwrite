import * as assert from 'assert';
import CucumberExpression from '../src/CucumberExpression.js';
import ExpressionFactory from '../src/ExpressionFactory.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
import RegularExpression from '../src/RegularExpression.js';
describe('ExpressionFactory', () => {
    let expressionFactory;
    beforeEach(() => {
        expressionFactory = new ExpressionFactory(new ParameterTypeRegistry());
    });
    it('creates a RegularExpression', () => {
        assert.strictEqual(expressionFactory.createExpression(/x/).constructor, RegularExpression);
    });
    it('creates a CucumberExpression', () => {
        assert.strictEqual(expressionFactory.createExpression('x').constructor, CucumberExpression);
    });
});
//# sourceMappingURL=ExpressionFactoryTest.js.map