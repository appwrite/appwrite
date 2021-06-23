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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.WorkerRunner = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const rimraf_1 = __importDefault(require("rimraf"));
const util_1 = __importDefault(require("util"));
const events_1 = require("events");
const util_2 = require("./util");
const globals_1 = require("./globals");
const loader_1 = require("./loader");
const test_1 = require("./test");
const fixtures_1 = require("./fixtures");
const removeFolderAsync = util_1.default.promisify(rimraf_1.default);
class WorkerRunner extends events_1.EventEmitter {
    constructor(params) {
        super();
        this._projectNamePathSegment = '';
        this._uniqueProjectNamePathSegment = '';
        this._entries = new Map();
        this._remaining = new Map();
        this._currentTest = null;
        this._params = params;
        this._fixtureRunner = new fixtures_1.FixtureRunner();
    }
    stop() {
        this._isStopped = true;
        this._setCurrentTest(null);
    }
    async cleanup() {
        // TODO: separate timeout for teardown?
        const result = await util_2.raceAgainstDeadline((async () => {
            await this._fixtureRunner.teardownScope('test');
            await this._fixtureRunner.teardownScope('worker');
        })(), this._deadline());
        if (result.timedOut)
            throw new Error(`Timeout of ${this._project.config.timeout}ms exceeded while shutting down environment`);
    }
    unhandledError(error) {
        if (this._isStopped)
            return;
        if (this._currentTest) {
            this._currentTest.testInfo.status = 'failed';
            this._currentTest.testInfo.error = util_2.serializeError(error);
            this._failedTestId = this._currentTest.testId;
            this.emit('testEnd', buildTestEndPayload(this._currentTest.testId, this._currentTest.testInfo));
        }
        else {
            // No current test - fatal error.
            this._fatalError = util_2.serializeError(error);
        }
        this._reportDoneAndStop();
    }
    _deadline() {
        return this._project.config.timeout ? util_2.monotonicTime() + this._project.config.timeout : undefined;
    }
    _loadIfNeeded() {
        if (this._loader)
            return;
        this._loader = loader_1.Loader.deserialize(this._params.loader);
        this._project = this._loader.projects()[this._params.projectIndex];
        this._projectNamePathSegment = sanitizeForFilePath(this._project.config.name);
        const sameName = this._loader.projects().filter(project => project.config.name === this._project.config.name);
        if (sameName.length > 1)
            this._uniqueProjectNamePathSegment = this._project.config.name + (sameName.indexOf(this._project) + 1);
        else
            this._uniqueProjectNamePathSegment = this._project.config.name;
        this._uniqueProjectNamePathSegment = sanitizeForFilePath(this._uniqueProjectNamePathSegment);
        this._workerInfo = {
            workerIndex: this._params.workerIndex,
            project: this._project.config,
            config: this._loader.fullConfig(),
        };
    }
    async run(runPayload) {
        this._entries = new Map(runPayload.entries.map(e => [e.testId, e]));
        this._remaining = new Map(runPayload.entries.map(e => [e.testId, e]));
        this._loadIfNeeded();
        const fileSuite = this._loader.loadTestFile(runPayload.file);
        let anySpec;
        fileSuite.findSpec(spec => {
            const test = this._project.generateTests(spec, this._params.repeatEachIndex)[0];
            if (this._entries.has(test._id))
                anySpec = spec;
        });
        if (!anySpec) {
            this._reportDone();
            return;
        }
        this._fixtureRunner.setPool(this._project.buildPool(anySpec));
        await this._runSuite(fileSuite);
        if (this._isStopped)
            return;
        this._reportDone();
    }
    async _runSuite(suite) {
        if (this._isStopped)
            return;
        const skipHooks = !this._hasTestsToRun(suite);
        for (const hook of suite._hooks) {
            if (hook.type !== 'beforeAll' || skipHooks)
                continue;
            if (this._isStopped)
                return;
            // TODO: separate timeout for beforeAll?
            const result = await util_2.raceAgainstDeadline(this._fixtureRunner.resolveParametersAndRunHookOrTest(hook.fn, 'worker', this._workerInfo), this._deadline());
            if (result.timedOut) {
                this._fatalError = util_2.serializeError(new Error(`Timeout of ${this._project.config.timeout}ms exceeded while running beforeAll hook`));
                this._reportDoneAndStop();
            }
        }
        for (const entry of suite._entries) {
            if (entry instanceof test_1.Suite)
                await this._runSuite(entry);
            else
                await this._runSpec(entry);
        }
        for (const hook of suite._hooks) {
            if (hook.type !== 'afterAll' || skipHooks)
                continue;
            if (this._isStopped)
                return;
            // TODO: separate timeout for afterAll?
            const result = await util_2.raceAgainstDeadline(this._fixtureRunner.resolveParametersAndRunHookOrTest(hook.fn, 'worker', this._workerInfo), this._deadline());
            if (result.timedOut) {
                this._fatalError = util_2.serializeError(new Error(`Timeout of ${this._project.config.timeout}ms exceeded while running afterAll hook`));
                this._reportDoneAndStop();
            }
        }
    }
    async _runSpec(spec) {
        if (this._isStopped)
            return;
        const test = spec.tests[0];
        const entry = this._entries.get(test._id);
        if (!entry)
            return;
        this._remaining.delete(test._id);
        const startTime = util_2.monotonicTime();
        let deadlineRunner;
        const testId = test._id;
        const baseOutputDir = (() => {
            const relativeTestFilePath = path_1.default.relative(this._project.config.testDir, spec.file.replace(/\.(spec|test)\.(js|ts)/, ''));
            const sanitizedRelativePath = relativeTestFilePath.replace(process.platform === 'win32' ? new RegExp('\\\\', 'g') : new RegExp('/', 'g'), '-');
            let testOutputDir = sanitizedRelativePath + '-' + sanitizeForFilePath(spec.title);
            if (this._uniqueProjectNamePathSegment)
                testOutputDir += '-' + this._uniqueProjectNamePathSegment;
            if (entry.retry)
                testOutputDir += '-retry' + entry.retry;
            if (this._params.repeatEachIndex)
                testOutputDir += '-repeat' + this._params.repeatEachIndex;
            return path_1.default.join(this._project.config.outputDir, testOutputDir);
        })();
        const testInfo = {
            ...this._workerInfo,
            title: spec.title,
            file: spec.file,
            line: spec.line,
            column: spec.column,
            fn: spec.fn,
            repeatEachIndex: this._params.repeatEachIndex,
            retry: entry.retry,
            expectedStatus: 'passed',
            annotations: [],
            duration: 0,
            status: 'passed',
            stdout: [],
            stderr: [],
            timeout: this._project.config.timeout,
            snapshotSuffix: '',
            outputDir: baseOutputDir,
            outputPath: (...pathSegments) => {
                fs_1.default.mkdirSync(baseOutputDir, { recursive: true });
                return path_1.default.join(baseOutputDir, ...pathSegments);
            },
            snapshotPath: (snapshotName) => {
                let suffix = '';
                if (this._projectNamePathSegment)
                    suffix += '-' + this._projectNamePathSegment;
                if (testInfo.snapshotSuffix)
                    suffix += '-' + testInfo.snapshotSuffix;
                if (suffix) {
                    const ext = path_1.default.extname(snapshotName);
                    if (ext)
                        snapshotName = snapshotName.substring(0, snapshotName.length - ext.length) + suffix + ext;
                    else
                        snapshotName += suffix;
                }
                return path_1.default.join(spec.file + '-snapshots', snapshotName);
            },
            skip: (...args) => modifier(testInfo, 'skip', args),
            fixme: (...args) => modifier(testInfo, 'fixme', args),
            fail: (...args) => modifier(testInfo, 'fail', args),
            slow: (...args) => modifier(testInfo, 'slow', args),
            setTimeout: (timeout) => {
                testInfo.timeout = timeout;
                if (deadlineRunner)
                    deadlineRunner.setDeadline(deadline());
            },
        };
        this._setCurrentTest({ testInfo, testId });
        const deadline = () => {
            return testInfo.timeout ? startTime + testInfo.timeout : undefined;
        };
        this.emit('testBegin', buildTestBeginPayload(testId, testInfo));
        if (testInfo.expectedStatus === 'skipped') {
            testInfo.status = 'skipped';
            this.emit('testEnd', buildTestEndPayload(testId, testInfo));
            return;
        }
        // Update the fixture pool - it may differ between tests, but only in test-scoped fixtures.
        this._fixtureRunner.setPool(this._project.buildPool(spec));
        deadlineRunner = new util_2.DeadlineRunner(this._runTestWithBeforeHooks(test, testInfo), deadline());
        const result = await deadlineRunner.result;
        // Do not overwrite test failure upon hook timeout.
        if (result.timedOut && testInfo.status === 'passed')
            testInfo.status = 'timedOut';
        if (this._isStopped)
            return;
        if (!result.timedOut) {
            deadlineRunner = new util_2.DeadlineRunner(this._runAfterHooks(test, testInfo), deadline());
            deadlineRunner.setDeadline(deadline());
            const hooksResult = await deadlineRunner.result;
            // Do not overwrite test failure upon hook timeout.
            if (hooksResult.timedOut && testInfo.status === 'passed')
                testInfo.status = 'timedOut';
        }
        else {
            // A timed-out test gets a full additional timeout to run after hooks.
            const newDeadline = this._deadline();
            deadlineRunner = new util_2.DeadlineRunner(this._runAfterHooks(test, testInfo), newDeadline);
            await deadlineRunner.result;
        }
        if (this._isStopped)
            return;
        testInfo.duration = util_2.monotonicTime() - startTime;
        this.emit('testEnd', buildTestEndPayload(testId, testInfo));
        const isFailure = testInfo.status === 'timedOut' || (testInfo.status === 'failed' && testInfo.expectedStatus !== 'failed');
        const preserveOutput = this._loader.fullConfig().preserveOutput === 'always' ||
            (this._loader.fullConfig().preserveOutput === 'failures-only' && isFailure);
        if (!preserveOutput)
            await removeFolderAsync(testInfo.outputDir).catch(e => { });
        if (testInfo.status !== 'passed') {
            this._failedTestId = testId;
            this._reportDoneAndStop();
        }
        this._setCurrentTest(null);
    }
    _setCurrentTest(currentTest) {
        this._currentTest = currentTest;
        globals_1.setCurrentTestInfo(currentTest ? currentTest.testInfo : null);
    }
    async _runTestWithBeforeHooks(test, testInfo) {
        try {
            await this._runHooks(test.spec.parent, 'beforeEach', testInfo);
        }
        catch (error) {
            if (error instanceof SkipError) {
                if (testInfo.status === 'passed')
                    testInfo.status = 'skipped';
            }
            else {
                testInfo.status = 'failed';
                testInfo.error = util_2.serializeError(error);
            }
            // Continue running afterEach hooks even after the failure.
        }
        // Do not run the test when beforeEach hook fails.
        if (this._isStopped || testInfo.status === 'failed' || testInfo.status === 'skipped')
            return;
        try {
            await this._fixtureRunner.resolveParametersAndRunHookOrTest(test.spec.fn, 'test', testInfo);
        }
        catch (error) {
            if (error instanceof SkipError) {
                if (testInfo.status === 'passed')
                    testInfo.status = 'skipped';
            }
            else {
                // We might fail after the timeout, e.g. due to fixture teardown.
                // Do not overwrite the timeout status with this error.
                if (testInfo.status === 'passed') {
                    testInfo.status = 'failed';
                    testInfo.error = util_2.serializeError(error);
                }
            }
        }
    }
    async _runAfterHooks(test, testInfo) {
        try {
            await this._runHooks(test.spec.parent, 'afterEach', testInfo);
        }
        catch (error) {
            // Do not overwrite test failure error.
            if (!(error instanceof SkipError) && testInfo.status === 'passed') {
                testInfo.status = 'failed';
                testInfo.error = util_2.serializeError(error);
                // Continue running even after the failure.
            }
        }
        try {
            await this._fixtureRunner.teardownScope('test');
        }
        catch (error) {
            // Do not overwrite test failure error.
            if (testInfo.status === 'passed') {
                testInfo.status = 'failed';
                testInfo.error = util_2.serializeError(error);
            }
        }
    }
    async _runHooks(suite, type, testInfo) {
        if (this._isStopped)
            return;
        const all = [];
        for (let s = suite; s; s = s.parent) {
            const funcs = s._hooks.filter(e => e.type === type).map(e => e.fn);
            all.push(...funcs.reverse());
        }
        if (type === 'beforeEach')
            all.reverse();
        let error;
        for (const hook of all) {
            try {
                await this._fixtureRunner.resolveParametersAndRunHookOrTest(hook, 'test', testInfo);
            }
            catch (e) {
                // Always run all the hooks, and capture the first error.
                error = error || e;
            }
        }
        if (error)
            throw error;
    }
    _reportDone() {
        const donePayload = {
            failedTestId: this._failedTestId,
            fatalError: this._fatalError,
            remaining: [...this._remaining.values()],
        };
        this.emit('done', donePayload);
    }
    _reportDoneAndStop() {
        if (this._isStopped)
            return;
        this._reportDone();
        this.stop();
    }
    _hasTestsToRun(suite) {
        return suite.findSpec(spec => {
            const entry = this._entries.get(spec.tests[0]._id);
            return !!entry;
        });
    }
}
exports.WorkerRunner = WorkerRunner;
function buildTestBeginPayload(testId, testInfo) {
    return {
        testId,
        workerIndex: testInfo.workerIndex
    };
}
function buildTestEndPayload(testId, testInfo) {
    return {
        testId,
        duration: testInfo.duration,
        status: testInfo.status,
        error: testInfo.error,
        expectedStatus: testInfo.expectedStatus,
        annotations: testInfo.annotations,
        timeout: testInfo.timeout,
    };
}
function modifier(testInfo, type, modifierArgs) {
    if (modifierArgs.length >= 1 && !modifierArgs[0])
        return;
    const description = modifierArgs[1];
    testInfo.annotations.push({ type, description });
    if (type === 'slow') {
        testInfo.setTimeout(testInfo.timeout * 3);
    }
    else if (type === 'skip' || type === 'fixme') {
        testInfo.expectedStatus = 'skipped';
        throw new SkipError('Test is skipped: ' + (description || ''));
    }
    else if (type === 'fail') {
        if (testInfo.expectedStatus !== 'skipped')
            testInfo.expectedStatus = 'failed';
    }
}
class SkipError extends Error {
}
function sanitizeForFilePath(s) {
    return s.replace(/[^\w\d]+/g, '-');
}
//# sourceMappingURL=workerRunner.js.map