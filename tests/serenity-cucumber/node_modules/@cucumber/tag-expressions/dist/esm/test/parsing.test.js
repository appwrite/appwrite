import assert from 'assert';
import fs from 'fs';
import yaml from 'js-yaml';
import parse from '../src/index.js';
import { testDataDir } from './testDataDir.js';
const tests = yaml.load(fs.readFileSync(`${testDataDir}/parsing.yml`, 'utf-8'));
describe('Parsing', () => {
    for (const test of tests) {
        it(`parses "${test.expression}" into "${test.formatted}"`, () => {
            const expression = parse(test.expression);
            assert.strictEqual(expression.toString(), test.formatted);
            const expressionAgain = parse(expression.toString());
            assert.strictEqual(expressionAgain.toString(), test.formatted);
        });
    }
});
//# sourceMappingURL=parsing.test.js.map