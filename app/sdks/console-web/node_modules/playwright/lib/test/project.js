"use strict";
/**
 * Copyright Microsoft Corporation. All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.ProjectImpl = void 0;
const test_1 = require("./test");
const fixtures_1 = require("./fixtures");
const testType_1 = require("./testType");
class ProjectImpl {
    constructor(project, index) {
        this.defines = new Map();
        this.testTypePools = new Map();
        this.specPools = new Map();
        this.config = project;
        this.index = index;
        this.defines = new Map();
        for (const { test, fixtures } of Array.isArray(project.define) ? project.define : [project.define])
            this.defines.set(test, fixtures);
    }
    buildTestTypePool(testType) {
        if (!this.testTypePools.has(testType)) {
            const fixtures = this.resolveFixtures(testType);
            const overrides = this.config.use;
            const overridesWithLocation = {
                fixtures: overrides,
                location: {
                    file: `<configuration file>`,
                    line: 1,
                    column: 1,
                }
            };
            const pool = new fixtures_1.FixturePool([...fixtures, overridesWithLocation]);
            this.testTypePools.set(testType, pool);
        }
        return this.testTypePools.get(testType);
    }
    buildPool(spec) {
        if (!this.specPools.has(spec)) {
            let pool = this.buildTestTypePool(spec._testType);
            const overrides = spec.parent._buildFixtureOverrides();
            if (Object.entries(overrides).length) {
                const overridesWithLocation = {
                    fixtures: overrides,
                    location: {
                        file: spec.file,
                        line: 1,
                        column: 1, // TODO: capture location
                    }
                };
                pool = new fixtures_1.FixturePool([overridesWithLocation], pool);
            }
            this.specPools.set(spec, pool);
            pool.validateFunction(spec.fn, 'Test', true, spec);
            for (let parent = spec.parent; parent; parent = parent.parent) {
                for (const hook of parent._hooks)
                    pool.validateFunction(hook.fn, hook.type + ' hook', hook.type === 'beforeEach' || hook.type === 'afterEach', hook.location);
            }
        }
        return this.specPools.get(spec);
    }
    generateTests(spec, repeatEachIndex) {
        const digest = this.buildPool(spec).digest;
        const min = repeatEachIndex === undefined ? 0 : repeatEachIndex;
        const max = repeatEachIndex === undefined ? this.config.repeatEach - 1 : repeatEachIndex;
        const tests = [];
        for (let i = min; i <= max; i++) {
            const test = new test_1.Test(spec);
            test.projectName = this.config.name;
            test.retries = this.config.retries;
            test._repeatEachIndex = i;
            test._projectIndex = this.index;
            test._workerHash = `run${this.index}-${digest}-repeat${i}`;
            test._id = `${spec._ordinalInFile}@${spec.file}#run${this.index}-repeat${i}`;
            spec.tests.push(test);
            tests.push(test);
        }
        return tests;
    }
    resolveFixtures(testType) {
        return testType.fixtures.map(f => {
            if (f instanceof testType_1.DeclaredFixtures) {
                const fixtures = this.defines.get(f.testType.test) || {};
                return { fixtures, location: f.location };
            }
            return f;
        });
    }
}
exports.ProjectImpl = ProjectImpl;
//# sourceMappingURL=project.js.map