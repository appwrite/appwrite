import assert from 'assert';
import fs from 'fs';
import glob from 'glob';
import yaml from 'js-yaml';
import ParameterTypeRegistry from '../src/ParameterTypeRegistry.js';
import RegularExpression from '../src/RegularExpression.js';
import { testDataDir } from './testDataDir.js';
describe('RegularExpression', () => {
    for (const path of glob.sync(`${testDataDir}/regular-expression/matching/*.yaml`)) {
        const expectation = yaml.load(fs.readFileSync(path, 'utf-8'));
        it(`matches ${path}`, () => {
            const parameterTypeRegistry = new ParameterTypeRegistry();
            const expression = new RegularExpression(new RegExp(expectation.expression), parameterTypeRegistry);
            const matches = expression.match(expectation.text);
            assert.deepStrictEqual(JSON.parse(JSON.stringify(matches ? matches.map((value) => value.getValue(null)) : null)), // Removes type information.
            expectation.expected_args);
        });
    }
    it('does no transform by default', () => {
        assert.deepStrictEqual(match(/(\d\d)/, '22'), ['22']);
    });
    it('does not transform anonymous', () => {
        assert.deepStrictEqual(match(/(.*)/, '22'), ['22']);
    });
    it('transforms negative int', () => {
        assert.deepStrictEqual(match(/(-?\d+)/, '-22'), [-22]);
    });
    it('transforms positive int', () => {
        assert.deepStrictEqual(match(/(\d+)/, '22'), [22]);
    });
    it('returns null when there is no match', () => {
        assert.strictEqual(match(/hello/, 'world'), null);
    });
    it('matches empty string', () => {
        assert.deepStrictEqual(match(/^The value equals "([^"]*)"$/, 'The value equals ""'), ['']);
    });
    it('matches nested capture group without match', () => {
        assert.deepStrictEqual(match(/^a user( named "([^"]*)")?$/, 'a user'), [null]);
    });
    it('matches nested capture group with match', () => {
        assert.deepStrictEqual(match(/^a user( named "([^"]*)")?$/, 'a user named "Charlie"'), [
            'Charlie',
        ]);
    });
    it('matches capture group nested in optional one', () => {
        const regexp = /^a (pre-commercial transaction |pre buyer fee model )?purchase(?: for \$(\d+))?$/;
        assert.deepStrictEqual(match(regexp, 'a purchase'), [null, null]);
        assert.deepStrictEqual(match(regexp, 'a purchase for $33'), [null, 33]);
        assert.deepStrictEqual(match(regexp, 'a pre buyer fee model purchase'), [
            'pre buyer fee model ',
            null,
        ]);
    });
    it('ignores non capturing groups', () => {
        assert.deepStrictEqual(match(/(\S+) ?(can|cannot)? (?:delete|cancel) the (\d+)(?:st|nd|rd|th) (attachment|slide) ?(?:upload)?/, 'I can cancel the 1st slide upload'), ['I', 'can', 1, 'slide']);
    });
    it('works with escaped parenthesis', () => {
        assert.deepStrictEqual(match(/Across the line\(s\)/, 'Across the line(s)'), []);
    });
    it('exposes regexp and source', () => {
        const regexp = /I have (\d+) cukes? in my (.+) now/;
        const expression = new RegularExpression(regexp, new ParameterTypeRegistry());
        assert.deepStrictEqual(expression.regexp, regexp);
        assert.deepStrictEqual(expression.source, regexp.source);
    });
    it('does not take consider parenthesis in character class as group', function () {
        const expression = new RegularExpression(/^drawings: ([A-Z_, ()]+)$/, new ParameterTypeRegistry());
        const args = expression.match('drawings: ONE, TWO(ABC)');
        assert.strictEqual(args[0].getValue(this), 'ONE, TWO(ABC)');
    });
});
const match = (regexp, text) => {
    const parameterRegistry = new ParameterTypeRegistry();
    const regularExpression = new RegularExpression(regexp, parameterRegistry);
    const args = regularExpression.match(text);
    if (!args) {
        return null;
    }
    return args.map((arg) => arg.getValue(null));
};
//# sourceMappingURL=RegularExpressionTest.js.map