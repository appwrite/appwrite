import assert from 'assert';
import fs from 'fs';
import yaml from 'js-yaml';
import parse from '../src/index.js';
import { testDataDir } from './testDataDir.js';
const tests = yaml.load(fs.readFileSync(`${testDataDir}/errors.yml`, 'utf-8'));
describe('Errors', () => {
    for (const test of tests) {
        it(`fails to parse "${test.expression}" with "${test.error}"`, () => {
            assert.throws(() => parse(test.expression), { message: test.error });
        });
    }
});
//# sourceMappingURL=errors.test.js.map