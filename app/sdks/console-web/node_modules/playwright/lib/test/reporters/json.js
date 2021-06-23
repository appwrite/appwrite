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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const empty_1 = __importDefault(require("./empty"));
function toPosixPath(aPath) {
    return aPath.split(path_1.default.sep).join(path_1.default.posix.sep);
}
class JSONReporter extends empty_1.default {
    constructor(options = {}) {
        super();
        this._errors = [];
        this._outputFile = options.outputFile;
    }
    onBegin(config, suite) {
        this.config = config;
        this.suite = suite;
    }
    onTimeout() {
        this.onEnd();
    }
    onError(error) {
        this._errors.push(error);
    }
    onEnd() {
        outputReport(this._serializeReport(), this._outputFile);
    }
    _serializeReport() {
        return {
            config: {
                ...this.config,
                rootDir: toPosixPath(this.config.rootDir),
                projects: this.config.projects.map(project => {
                    return {
                        outputDir: toPosixPath(project.outputDir),
                        repeatEach: project.repeatEach,
                        retries: project.retries,
                        metadata: project.metadata,
                        name: project.name,
                        testDir: toPosixPath(project.testDir),
                        testIgnore: serializePatterns(project.testIgnore),
                        testMatch: serializePatterns(project.testMatch),
                        timeout: project.timeout,
                    };
                })
            },
            suites: this.suite.suites.map(suite => this._serializeSuite(suite)).filter(s => s),
            errors: this._errors
        };
    }
    _serializeSuite(suite) {
        if (!suite.findSpec(test => true))
            return null;
        const suites = suite.suites.map(suite => this._serializeSuite(suite)).filter(s => s);
        return {
            title: suite.title,
            file: toPosixPath(path_1.default.relative(this.config.rootDir, suite.file)),
            line: suite.line,
            column: suite.column,
            specs: suite.specs.map(test => this._serializeTestSpec(test)),
            suites: suites.length ? suites : undefined,
        };
    }
    _serializeTestSpec(spec) {
        return {
            title: spec.title,
            ok: spec.ok(),
            tests: spec.tests.map(r => this._serializeTest(r)),
            file: toPosixPath(path_1.default.relative(this.config.rootDir, spec.file)),
            line: spec.line,
            column: spec.column,
        };
    }
    _serializeTest(test) {
        return {
            timeout: test.timeout,
            annotations: test.annotations,
            expectedStatus: test.expectedStatus,
            projectName: test.projectName,
            results: test.results.map(r => this._serializeTestResult(r)),
        };
    }
    _serializeTestResult(result) {
        return {
            workerIndex: result.workerIndex,
            status: result.status,
            duration: result.duration,
            error: result.error,
            stdout: result.stdout.map(s => stdioEntry(s)),
            stderr: result.stderr.map(s => stdioEntry(s)),
            retry: result.retry,
        };
    }
}
function outputReport(report, outputFile) {
    const reportString = JSON.stringify(report, undefined, 2);
    outputFile = outputFile || process.env[`PLAYWRIGHT_JSON_OUTPUT_NAME`];
    if (outputFile) {
        fs_1.default.mkdirSync(path_1.default.dirname(outputFile), { recursive: true });
        fs_1.default.writeFileSync(outputFile, reportString);
    }
    else {
        console.log(reportString);
    }
}
function stdioEntry(s) {
    if (typeof s === 'string')
        return { text: s };
    return { buffer: s.toString('base64') };
}
function serializePatterns(patterns) {
    if (!Array.isArray(patterns))
        patterns = [patterns];
    return patterns.map(s => s.toString());
}
exports.default = JSONReporter;
//# sourceMappingURL=json.js.map