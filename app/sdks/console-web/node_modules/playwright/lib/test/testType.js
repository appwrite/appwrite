"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.rootTestType = exports.TestTypeImpl = exports.DeclaredFixtures = void 0;
const expect_1 = require("./expect");
const globals_1 = require("./globals");
const test_1 = require("./test");
const util_1 = require("./util");
const fixtures_1 = require("./fixtures");
Error.stackTraceLimit = 15;
const countByFile = new Map();
class DeclaredFixtures {
}
exports.DeclaredFixtures = DeclaredFixtures;
class TestTypeImpl {
    constructor(fixtures) {
        this.fixtures = fixtures;
        const test = this._spec.bind(this, 'default');
        test.expect = expect_1.expect;
        test.only = this._spec.bind(this, 'only');
        test.describe = this._describe.bind(this, 'default');
        test.describe.only = this._describe.bind(this, 'only');
        test.beforeEach = this._hook.bind(this, 'beforeEach');
        test.afterEach = this._hook.bind(this, 'afterEach');
        test.beforeAll = this._hook.bind(this, 'beforeAll');
        test.afterAll = this._hook.bind(this, 'afterAll');
        test.skip = this._modifier.bind(this, 'skip');
        test.fixme = this._modifier.bind(this, 'fixme');
        test.fail = this._modifier.bind(this, 'fail');
        test.slow = this._modifier.bind(this, 'slow');
        test.setTimeout = this._setTimeout.bind(this);
        test.use = this._use.bind(this);
        test.extend = this._extend.bind(this);
        test.declare = this._declare.bind(this);
        this.test = test;
    }
    _spec(type, title, fn) {
        const suite = globals_1.currentlyLoadingFileSuite();
        if (!suite)
            throw util_1.errorWithCallLocation(`test() can only be called in a test file`);
        const location = util_1.callLocation(suite.file);
        const ordinalInFile = countByFile.get(suite.file) || 0;
        countByFile.set(location.file, ordinalInFile + 1);
        const spec = new test_1.Spec(title, fn, ordinalInFile, this);
        spec.file = location.file;
        spec.line = location.line;
        spec.column = location.column;
        suite._addSpec(spec);
        if (type === 'only')
            spec._only = true;
    }
    _describe(type, title, fn) {
        const suite = globals_1.currentlyLoadingFileSuite();
        if (!suite)
            throw util_1.errorWithCallLocation(`describe() can only be called in a test file`);
        const location = util_1.callLocation(suite.file);
        const child = new test_1.Suite(title);
        child.file = suite.file;
        child.line = location.line;
        child.column = location.column;
        suite._addSuite(child);
        if (type === 'only')
            child._only = true;
        globals_1.setCurrentlyLoadingFileSuite(child);
        fn();
        globals_1.setCurrentlyLoadingFileSuite(suite);
    }
    _hook(name, fn) {
        const suite = globals_1.currentlyLoadingFileSuite();
        if (!suite)
            throw util_1.errorWithCallLocation(`${name} hook can only be called in a test file`);
        suite._hooks.push({ type: name, fn, location: util_1.callLocation() });
    }
    _modifier(type, ...modiferAgs) {
        const suite = globals_1.currentlyLoadingFileSuite();
        if (suite) {
            const location = util_1.callLocation();
            if (typeof modiferAgs[0] === 'function') {
                const [conditionFn, description] = modiferAgs;
                const fn = (args, testInfo) => testInfo[type](conditionFn(args), description);
                fixtures_1.inheritFixtureParameterNames(conditionFn, fn, location);
                suite._hooks.unshift({ type: 'beforeEach', fn, location });
            }
            else {
                const fn = ({}, testInfo) => testInfo[type](...modiferAgs);
                suite._hooks.unshift({ type: 'beforeEach', fn, location });
            }
            return;
        }
        const testInfo = globals_1.currentTestInfo();
        if (!testInfo)
            throw new Error(`test.${type}() can only be called inside test, describe block or fixture`);
        if (typeof modiferAgs[0] === 'function')
            throw new Error(`test.${type}() with a function can only be called inside describe block`);
        testInfo[type](...modiferAgs);
    }
    _setTimeout(timeout) {
        const testInfo = globals_1.currentTestInfo();
        if (!testInfo)
            throw new Error(`test.setTimeout() can only be called inside test or fixture`);
        testInfo.setTimeout(timeout);
    }
    _use(fixtures) {
        const suite = globals_1.currentlyLoadingFileSuite();
        if (!suite)
            throw util_1.errorWithCallLocation(`test.use() can only be called in a test file`);
        suite._fixtureOverrides = { ...suite._fixtureOverrides, ...fixtures };
    }
    _extend(fixtures) {
        const fixturesWithLocation = {
            fixtures,
            location: util_1.callLocation(),
        };
        return new TestTypeImpl([...this.fixtures, fixturesWithLocation]).test;
    }
    _declare() {
        const declared = new DeclaredFixtures();
        declared.location = util_1.callLocation();
        const child = new TestTypeImpl([...this.fixtures, declared]);
        declared.testType = child;
        return child.test;
    }
}
exports.TestTypeImpl = TestTypeImpl;
exports.rootTestType = new TestTypeImpl([]);
//# sourceMappingURL=testType.js.map