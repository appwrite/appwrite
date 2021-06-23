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
exports.stripAscii = exports.formatTestTitle = exports.formatFailure = exports.BaseReporter = void 0;
const code_frame_1 = require("@babel/code-frame");
const safe_1 = __importDefault(require("colors/safe"));
const fs_1 = __importDefault(require("fs"));
// @ts-ignore
const ms_1 = __importDefault(require("ms"));
const path_1 = __importDefault(require("path"));
const stack_utils_1 = __importDefault(require("stack-utils"));
const stackUtils = new stack_utils_1.default();
class BaseReporter {
    constructor() {
        this.duration = 0;
        this.timeout = 0;
        this.fileDurations = new Map();
        this.monotonicStartTime = 0;
    }
    onBegin(config, suite) {
        this.monotonicStartTime = monotonicTime();
        this.config = config;
        this.suite = suite;
    }
    onTestBegin(test) {
    }
    onStdOut(chunk) {
        if (!this.config.quiet)
            process.stdout.write(chunk);
    }
    onStdErr(chunk) {
        if (!this.config.quiet)
            process.stderr.write(chunk);
    }
    onTestEnd(test, result) {
        const spec = test.spec;
        let duration = this.fileDurations.get(spec.file) || 0;
        duration += result.duration;
        this.fileDurations.set(spec.file, duration);
    }
    onError(error) {
        console.log(formatError(error));
    }
    onTimeout(timeout) {
        this.timeout = timeout;
    }
    onEnd() {
        this.duration = monotonicTime() - this.monotonicStartTime;
    }
    _printSlowTests() {
        const fileDurations = [...this.fileDurations.entries()];
        fileDurations.sort((a, b) => b[1] - a[1]);
        for (let i = 0; i < 10 && i < fileDurations.length; ++i) {
            const baseName = path_1.default.basename(fileDurations[i][0]);
            const duration = fileDurations[i][1];
            if (duration < 15000)
                break;
            console.log(safe_1.default.yellow('  Slow test: ') + baseName + safe_1.default.yellow(` (${ms_1.default(duration)})`));
        }
    }
    epilogue(full) {
        let skipped = 0;
        let expected = 0;
        const unexpected = [];
        const flaky = [];
        this.suite.findTest(test => {
            switch (test.status()) {
                case 'skipped':
                    ++skipped;
                    break;
                case 'expected':
                    ++expected;
                    break;
                case 'unexpected':
                    unexpected.push(test);
                    break;
                case 'flaky':
                    flaky.push(test);
                    break;
            }
        });
        if (full && unexpected.length) {
            console.log('');
            this._printFailures(unexpected);
        }
        this._printSlowTests();
        console.log('');
        if (unexpected.length) {
            console.log(safe_1.default.red(`  ${unexpected.length} failed`));
            this._printTestHeaders(unexpected);
        }
        if (flaky.length) {
            console.log(safe_1.default.red(`  ${flaky.length} flaky`));
            this._printTestHeaders(flaky);
        }
        if (skipped)
            console.log(safe_1.default.yellow(`  ${skipped} skipped`));
        if (expected)
            console.log(safe_1.default.green(`  ${expected} passed`) + safe_1.default.dim(` (${ms_1.default(this.duration)})`));
        if (this.timeout)
            console.log(safe_1.default.red(`  Timed out waiting ${this.timeout / 1000}s for the entire test run`));
    }
    _printTestHeaders(tests) {
        tests.forEach(test => {
            console.log(formatTestHeader(this.config, test, '    '));
        });
    }
    _printFailures(failures) {
        failures.forEach((test, index) => {
            console.log(formatFailure(this.config, test, index + 1));
        });
    }
    hasResultWithStatus(test, status) {
        return !!test.results.find(r => r.status === status);
    }
    willRetry(test, result) {
        return result.status !== 'passed' && result.status !== test.expectedStatus && test.results.length <= test.retries;
    }
}
exports.BaseReporter = BaseReporter;
function formatFailure(config, test, index) {
    const tokens = [];
    tokens.push(formatTestHeader(config, test, '  ', index));
    for (const result of test.results) {
        if (result.status === 'passed')
            continue;
        tokens.push(formatFailedResult(test, result));
    }
    tokens.push('');
    return tokens.join('\n');
}
exports.formatFailure = formatFailure;
function formatTestTitle(config, test) {
    const spec = test.spec;
    let relativePath = path_1.default.relative(config.rootDir, spec.file) || path_1.default.basename(spec.file);
    relativePath += ':' + spec.line + ':' + spec.column;
    return `${relativePath} › ${test.fullTitle()}`;
}
exports.formatTestTitle = formatTestTitle;
function formatTestHeader(config, test, indent, index) {
    const title = formatTestTitle(config, test);
    const passedUnexpectedlySuffix = test.results[0].status === 'passed' ? ' -- passed unexpectedly' : '';
    const header = `${indent}${index ? index + ') ' : ''}${title}${passedUnexpectedlySuffix}`;
    return safe_1.default.red(pad(header, '='));
}
function formatFailedResult(test, result) {
    const tokens = [];
    if (result.retry)
        tokens.push(safe_1.default.gray(pad(`\n    Retry #${result.retry}`, '-')));
    if (result.status === 'timedOut') {
        tokens.push('');
        tokens.push(indent(safe_1.default.red(`Timeout of ${test.timeout}ms exceeded.`), '    '));
    }
    else {
        tokens.push(indent(formatError(result.error, test.spec.file), '    '));
    }
    return tokens.join('\n');
}
function formatError(error, file) {
    const stack = error.stack;
    const tokens = [];
    if (stack) {
        tokens.push('');
        const message = error.message || '';
        const messageLocation = stack.indexOf(message);
        const preamble = stack.substring(0, messageLocation + message.length);
        tokens.push(preamble);
        const position = file ? positionInFile(stack, file) : null;
        if (position) {
            const source = fs_1.default.readFileSync(file, 'utf8');
            tokens.push('');
            tokens.push(code_frame_1.codeFrameColumns(source, {
                start: position,
            }, { highlightCode: safe_1.default.enabled }));
        }
        tokens.push('');
        tokens.push(safe_1.default.dim(preamble.length > 0 ? stack.substring(preamble.length + 1) : stack));
    }
    else {
        tokens.push('');
        tokens.push(error.value);
    }
    return tokens.join('\n');
}
function pad(line, char) {
    return line + ' ' + safe_1.default.gray(char.repeat(Math.max(0, 100 - line.length - 1)));
}
function indent(lines, tab) {
    return lines.replace(/^(?=.+$)/gm, tab);
}
function positionInFile(stack, file) {
    // Stack will have /private/var/folders instead of /var/folders on Mac.
    file = fs_1.default.realpathSync(file);
    for (const line of stack.split('\n')) {
        const parsed = stackUtils.parseLine(line);
        if (!parsed || !parsed.file)
            continue;
        if (path_1.default.resolve(process.cwd(), parsed.file) === file)
            return { column: parsed.column || 0, line: parsed.line || 0 };
    }
    return { column: 0, line: 0 };
}
function monotonicTime() {
    const [seconds, nanoseconds] = process.hrtime();
    return seconds * 1000 + (nanoseconds / 1000000 | 0);
}
const asciiRegex = new RegExp('[\\u001B\\u009B][[\\]()#;?]*(?:(?:(?:[a-zA-Z\\d]*(?:;[-a-zA-Z\\d\\/#&.:=?%@~_]*)*)?\\u0007)|(?:(?:\\d{1,4}(?:;\\d{0,4})*)?[\\dA-PR-TZcf-ntqry=><~]))', 'g');
function stripAscii(str) {
    return str.replace(asciiRegex, '');
}
exports.stripAscii = stripAscii;
//# sourceMappingURL=base.js.map