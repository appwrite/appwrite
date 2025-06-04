import assert from 'assert';
import fs from 'fs';
import glob from 'glob';
import yaml from 'js-yaml';
import CucumberExpression from '../src/CucumberExpression.js';
import CucumberExpressionError from '../src/CucumberExpressionError.js';
import ParameterType from '../src/ParameterType.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
import { testDataDir } from './testDataDir.js';
describe('CucumberExpression', () => {
    for (const path of glob.sync(`${testDataDir}/cucumber-expression/matching/*.yaml`)) {
        const expectation = yaml.load(fs.readFileSync(path, 'utf-8'));
        it(`matches ${path}`, () => {
            const parameterTypeRegistry = new ParameterTypeRegistry();
            if (expectation.expected_args !== undefined) {
                const expression = new CucumberExpression(expectation.expression, parameterTypeRegistry);
                const matches = expression.match(expectation.text);
                assert.deepStrictEqual(JSON.parse(JSON.stringify(matches ? matches.map((value) => value.getValue(null)) : null, (key, value) => {
                    return typeof value === 'bigint' ? value.toString() : value;
                })), // Removes type information.
                expectation.expected_args);
            }
            else if (expectation.exception !== undefined) {
                assert.throws(() => {
                    const expression = new CucumberExpression(expectation.expression, parameterTypeRegistry);
                    expression.match(expectation.text);
                }, new CucumberExpressionError(expectation.exception));
            }
            else {
                throw new Error(`Expectation must have expected or exception: ${JSON.stringify(expectation)}`);
            }
        });
    }
    it('matches float', () => {
        assert.deepStrictEqual(match('{float}', ''), null);
        assert.deepStrictEqual(match('{float}', '.'), null);
        assert.deepStrictEqual(match('{float}', ','), null);
        assert.deepStrictEqual(match('{float}', '-'), null);
        assert.deepStrictEqual(match('{float}', 'E'), null);
        assert.deepStrictEqual(match('{float}', '1,'), null);
        assert.deepStrictEqual(match('{float}', ',1'), null);
        assert.deepStrictEqual(match('{float}', '1.'), null);
        assert.deepStrictEqual(match('{float}', '1'), [1]);
        assert.deepStrictEqual(match('{float}', '-1'), [-1]);
        assert.deepStrictEqual(match('{float}', '1.1'), [1.1]);
        assert.deepStrictEqual(match('{float}', '1,000'), null);
        assert.deepStrictEqual(match('{float}', '1,000,0'), null);
        assert.deepStrictEqual(match('{float}', '1,000.1'), null);
        assert.deepStrictEqual(match('{float}', '1,000,10'), null);
        assert.deepStrictEqual(match('{float}', '1,0.1'), null);
        assert.deepStrictEqual(match('{float}', '1,000,000.1'), null);
        assert.deepStrictEqual(match('{float}', '-1.1'), [-1.1]);
        assert.deepStrictEqual(match('{float}', '.1'), [0.1]);
        assert.deepStrictEqual(match('{float}', '-.1'), [-0.1]);
        assert.deepStrictEqual(match('{float}', '-.10000001'), [-0.10000001]);
        assert.deepStrictEqual(match('{float}', '1E1'), [1e1]); // precision 1 with scale -1, can not be expressed as a decimal
        assert.deepStrictEqual(match('{float}', '.1E1'), [1]);
        assert.deepStrictEqual(match('{float}', 'E1'), null);
        assert.deepStrictEqual(match('{float}', '-.1E-1'), [-0.01]);
        assert.deepStrictEqual(match('{float}', '-.1E-2'), [-0.001]);
        assert.deepStrictEqual(match('{float}', '-.1E+1'), [-1]);
        assert.deepStrictEqual(match('{float}', '-.1E+2'), [-10]);
        assert.deepStrictEqual(match('{float}', '-.1E1'), [-1]);
        assert.deepStrictEqual(match('{float}', '-.10E2'), [-10]);
    });
    it('matches float with zero', () => {
        assert.deepEqual(match('{float}', '0'), [0]);
    });
    it('exposes source', () => {
        const expr = 'I have {int} cuke(s)';
        assert.strictEqual(new CucumberExpression(expr, new ParameterTypeRegistry()).source, expr);
    });
    it('unmatched optional groups have undefined values', () => {
        const parameterTypeRegistry = new ParameterTypeRegistry();
        parameterTypeRegistry.defineParameterType(new ParameterType('textAndOrNumber', /([A-Z]+)?(?: )?([0-9]+)?/, null, function (s1, s2) {
            return [s1, s2];
        }, false, true));
        const expression = new CucumberExpression('{textAndOrNumber}', parameterTypeRegistry);
        const world = {};
        assert.deepStrictEqual(expression.match(`TLA`)[0].getValue(world), ['TLA', undefined]);
        assert.deepStrictEqual(expression.match(`123`)[0].getValue(world), [undefined, '123']);
    });
    // JavaScript-specific
    it('delegates transform to custom object', () => {
        const parameterTypeRegistry = new ParameterTypeRegistry();
        parameterTypeRegistry.defineParameterType(new ParameterType('widget', /\w+/, null, function (s) {
            return this.createWidget(s);
        }, false, true));
        const expression = new CucumberExpression('I have a {widget}', parameterTypeRegistry);
        const world = {
            createWidget(s) {
                return `widget:${s}`;
            },
        };
        const args = expression.match(`I have a bolt`);
        assert.strictEqual(args[0].getValue(world), 'widget:bolt');
    });
});
const match = (expression, text) => {
    const cucumberExpression = new CucumberExpression(expression, new ParameterTypeRegistry());
    const args = cucumberExpression.match(text);
    if (!args) {
        return null;
    }
    return args.map((arg) => arg.getValue(null));
};
//# sourceMappingURL=CucumberExpressionTest.js.map