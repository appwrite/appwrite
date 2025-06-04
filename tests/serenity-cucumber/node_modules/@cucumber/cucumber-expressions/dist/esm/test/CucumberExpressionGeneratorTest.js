import assert from 'assert';
import CucumberExpression from '../src/CucumberExpression.js';
import CucumberExpressionGenerator from '../src/CucumberExpressionGenerator.js';
import ParameterType from '../src/ParameterType.js';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
class Currency {
    constructor(s) {
        this.s = s;
    }
}
describe('CucumberExpressionGenerator', () => {
    let parameterTypeRegistry;
    let generator;
    function assertExpression(expectedExpression, expectedParameterInfo, text) {
        const generatedExpression = generator.generateExpressions(text)[0];
        assert.deepStrictEqual(generatedExpression.parameterInfos, expectedParameterInfo);
        assert.strictEqual(generatedExpression.source, expectedExpression);
        const cucumberExpression = new CucumberExpression(generatedExpression.source, parameterTypeRegistry);
        const match = cucumberExpression.match(text);
        if (match === null) {
            assert.fail(`Expected text '${text}' to match generated expression '${generatedExpression.source}'`);
        }
        assert.strictEqual(match.length, expectedParameterInfo.length);
    }
    beforeEach(() => {
        parameterTypeRegistry = new ParameterTypeRegistry();
        generator = new CucumberExpressionGenerator(() => parameterTypeRegistry.parameterTypes);
    });
    it('documents expression generation', () => {
        parameterTypeRegistry = new ParameterTypeRegistry();
        generator = new CucumberExpressionGenerator(() => parameterTypeRegistry.parameterTypes);
        const undefinedStepText = 'I have 2 cucumbers and 1.5 tomato';
        const generatedExpression = generator.generateExpressions(undefinedStepText)[0];
        assert.strictEqual(generatedExpression.source, 'I have {int} cucumbers and {float} tomato');
        assert.strictEqual(generatedExpression.parameterNames[0], 'int');
        assert.strictEqual(generatedExpression.parameterTypes[1].name, 'float');
    });
    it('generates expression for no args', () => {
        assertExpression('hello', [], 'hello');
    });
    it('generates expression with escaped left parenthesis', () => {
        assertExpression('\\(iii)', [], '(iii)');
    });
    it('generates expression with escaped left curly brace', () => {
        assertExpression('\\{iii}', [], '{iii}');
    });
    it('generates expression with escaped slashes', () => {
        assertExpression('The {int}\\/{int}\\/{int} hey', [
            {
                type: 'Number',
                name: 'int',
                count: 1,
            },
            {
                type: 'Number',
                name: 'int',
                count: 2,
            },
            {
                type: 'Number',
                name: 'int',
                count: 3,
            },
        ], 'The 1814/05/17 hey');
    });
    it('generates expression for int float arg', () => {
        assertExpression('I have {int} cukes and {float} euro', [
            {
                type: 'Number',
                name: 'int',
                count: 1,
            },
            {
                type: 'Number',
                name: 'float',
                count: 1,
            },
        ], 'I have 2 cukes and 1.5 euro');
    });
    it('generates expression for strings', () => {
        assertExpression('I like {string} and {string}', [
            {
                type: 'String',
                name: 'string',
                count: 1,
            },
            {
                type: 'String',
                name: 'string',
                count: 2,
            },
        ], 'I like "bangers" and \'mash\'');
    });
    it('generates expression with % sign', () => {
        assertExpression('I am {int}%% foobar', [
            {
                type: 'Number',
                name: 'int',
                count: 1,
            },
        ], 'I am 20%% foobar');
    });
    it('generates expression for just int', () => {
        assertExpression('{int}', [
            {
                type: 'Number',
                name: 'int',
                count: 1,
            },
        ], '99999');
    });
    it('numbers only second argument when builtin type is not reserved keyword', () => {
        assertExpression('I have {float} cukes and {float} euro', [
            {
                type: 'Number',
                name: 'float',
                count: 1,
            },
            {
                type: 'Number',
                name: 'float',
                count: 2,
            },
        ], 'I have 2.5 cukes and 1.5 euro');
    });
    it('generates expression for custom type', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('currency', /[A-Z]{3}/, Currency, (s) => new Currency(s), true, false));
        assertExpression('I have a {currency} account', [
            {
                type: 'Currency',
                name: 'currency',
                count: 1,
            },
        ], 'I have a EUR account');
    });
    it('prefers leftmost match when there is overlap', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('currency', /c d/, Currency, (s) => new Currency(s), true, false));
        parameterTypeRegistry.defineParameterType(new ParameterType('date', /b c/, Date, (s) => new Date(s), true, false));
        assertExpression('a {date} d e f g', [
            {
                type: 'Date',
                name: 'date',
                count: 1,
            },
        ], 'a b c d e f g');
    });
    // TODO: prefers widest match
    it('generates all combinations of expressions when several parameter types match', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('currency', /x/, null, (s) => new Currency(s), true, false));
        parameterTypeRegistry.defineParameterType(new ParameterType('date', /x/, null, (s) => new Date(s), true, false));
        const generatedExpressions = generator.generateExpressions('I have x and x and another x');
        const expressions = generatedExpressions.map((e) => e.source);
        assert.deepStrictEqual(expressions, [
            'I have {currency} and {currency} and another {currency}',
            'I have {currency} and {currency} and another {date}',
            'I have {currency} and {date} and another {currency}',
            'I have {currency} and {date} and another {date}',
            'I have {date} and {currency} and another {currency}',
            'I have {date} and {currency} and another {date}',
            'I have {date} and {date} and another {currency}',
            'I have {date} and {date} and another {date}',
        ]);
    });
    it('exposes parameter type names in generated expression', () => {
        const expression = generator.generateExpressions('I have 2 cukes and 1.5 euro')[0];
        const typeNames = expression.parameterTypes.map((parameter) => parameter.name);
        assert.deepStrictEqual(typeNames, ['int', 'float']);
    });
    it('matches parameter types with optional capture groups', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('optional-flight', /(1st flight)?/, null, (s) => s, true, false));
        parameterTypeRegistry.defineParameterType(new ParameterType('optional-hotel', /(1 hotel)?/, null, (s) => s, true, false));
        const expression = generator.generateExpressions('I reach Stage 4: 1st flight -1 hotel')[0];
        // While you would expect this to be `I reach Stage {int}: {optional-flight} -{optional-hotel}` the `-1` causes
        // {int} to match just before {optional-hotel}.
        assert.strictEqual(expression.source, 'I reach Stage {int}: {optional-flight} {int} hotel');
    });
    it('generates at most 256 expressions', () => {
        for (let i = 0; i < 4; i++) {
            parameterTypeRegistry.defineParameterType(new ParameterType('my-type-' + i, /([a-z] )*?[a-z]/, null, (s) => s, true, false));
        }
        // This would otherwise generate 4^11=419430 expressions and consume just shy of 1.5GB.
        const expressions = generator.generateExpressions('a s i m p l e s t e p');
        assert.strictEqual(expressions.length, 256);
    });
    it('prefers expression with longest non empty match', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('zero-or-more', /[a-z]*/, null, (s) => s, true, false));
        parameterTypeRegistry.defineParameterType(new ParameterType('exactly-one', /[a-z]/, null, (s) => s, true, false));
        const expressions = generator.generateExpressions('a simple step');
        assert.strictEqual(expressions.length, 2);
        assert.strictEqual(expressions[0].source, '{exactly-one} {zero-or-more} {zero-or-more}');
        assert.strictEqual(expressions[1].source, '{zero-or-more} {zero-or-more} {zero-or-more}');
    });
    it('does not suggest parameter included at the beginning of a word', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('direction', /(up|down)/, null, (s) => s, true, false));
        const expressions = generator.generateExpressions('I download a picture');
        assert.strictEqual(expressions.length, 1);
        assert.notStrictEqual(expressions[0].source, 'I {direction}load a picture');
        assert.strictEqual(expressions[0].source, 'I download a picture');
    });
    it('does not suggest parameter included inside a word', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('direction', /(up|down)/, null, (s) => s, true, false));
        const expressions = generator.generateExpressions('I watch the muppet show');
        assert.strictEqual(expressions.length, 1);
        assert.notStrictEqual(expressions[0].source, 'I watch the m{direction}pet show');
        assert.strictEqual(expressions[0].source, 'I watch the muppet show');
    });
    it('does not suggest parameter at the end of a word', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('direction', /(up|down)/, null, (s) => s, true, false));
        const expressions = generator.generateExpressions('I create a group');
        assert.strictEqual(expressions.length, 1);
        assert.notStrictEqual(expressions[0].source, 'I create a gro{direction}');
        assert.strictEqual(expressions[0].source, 'I create a group');
    });
    it('does suggest parameter that are a full word', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('direction', /(up|down)/, null, (s) => s, true, false));
        assert.strictEqual(generator.generateExpressions('When I go down the road')[0].source, 'When I go {direction} the road');
        assert.strictEqual(generator.generateExpressions('When I walk up the hill')[0].source, 'When I walk {direction} the hill');
        assert.strictEqual(generator.generateExpressions('up the hill, the road goes down')[0].source, '{direction} the hill, the road goes {direction}');
    });
    it('does not consider punctuation as being part of a word', () => {
        parameterTypeRegistry.defineParameterType(new ParameterType('direction', /(up|down)/, null, (s) => s, true, false));
        assert.strictEqual(generator.generateExpressions('direction is:down')[0].source, 'direction is:{direction}');
        assert.strictEqual(generator.generateExpressions('direction is down.')[0].source, 'direction is {direction}.');
    });
});
//# sourceMappingURL=CucumberExpressionGeneratorTest.js.map