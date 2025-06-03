import assert from 'assert';
import fs from 'fs';
import yaml from 'js-yaml';
import parse from '../src/index.js';
import { testDataDir } from './testDataDir.js';
const evaluationsTest = yaml.load(fs.readFileSync(`${testDataDir}/evaluations.yml`, 'utf-8'));
describe('Evaluations', () => {
    for (const evaluation of evaluationsTest) {
        describe(evaluation.expression, () => {
            for (const test of evaluation.tests) {
                it(`evaluates [${test.variables.join(', ')}] to ${test.result}`, () => {
                    const node = parse(evaluation.expression);
                    assert.strictEqual(node.evaluate(test.variables), test.result);
                });
            }
        });
    }
});
//# sourceMappingURL=evaluations.test.js.map