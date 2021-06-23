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
exports.forceRegExp = exports.formatLocation = exports.wrapInPromise = exports.mergeObjects = exports.createMatcher = exports.isRegExp = exports.prependErrorMessage = exports.monotonicTime = exports.errorWithCallLocation = exports.callLocation = exports.serializeError = exports.raceAgainstDeadline = exports.DeadlineRunner = void 0;
const path_1 = __importDefault(require("path"));
const util_1 = __importDefault(require("util"));
const stack_utils_1 = __importDefault(require("stack-utils"));
const minimatch_1 = __importDefault(require("minimatch"));
const TEST_RUNNER_DIRS = [
    path_1.default.join('@playwright', 'test', 'lib'),
    path_1.default.join(__dirname, '..', '..', 'src', 'test'),
];
const cwd = process.cwd();
const stackUtils = new stack_utils_1.default({ cwd });
class DeadlineRunner {
    constructor(promise, deadline) {
        this._done = false;
        this.result = new Promise((f, r) => {
            this._fulfill = f;
            this._reject = r;
        });
        promise.then(result => {
            this._finish({ result });
        }).catch(e => {
            this._finish(undefined, e);
        });
        this.setDeadline(deadline);
    }
    _finish(success, error) {
        if (this._done)
            return;
        this.setDeadline(undefined);
        if (success)
            this._fulfill(success);
        else
            this._reject(error);
    }
    setDeadline(deadline) {
        if (this._timer) {
            clearTimeout(this._timer);
            this._timer = undefined;
        }
        if (deadline === undefined)
            return;
        const timeout = deadline - monotonicTime();
        if (timeout <= 0)
            this._finish({ timedOut: true });
        else
            this._timer = setTimeout(() => this._finish({ timedOut: true }), timeout);
    }
}
exports.DeadlineRunner = DeadlineRunner;
async function raceAgainstDeadline(promise, deadline) {
    return (new DeadlineRunner(promise, deadline)).result;
}
exports.raceAgainstDeadline = raceAgainstDeadline;
function serializeError(error) {
    if (error instanceof Error) {
        return {
            message: error.message,
            stack: error.stack
        };
    }
    return {
        value: util_1.default.inspect(error)
    };
}
exports.serializeError = serializeError;
function callFrames() {
    const obj = { stack: '' };
    Error.captureStackTrace(obj);
    const frames = obj.stack.split('\n').slice(1);
    while (frames.length && TEST_RUNNER_DIRS.some(dir => frames[0].includes(dir)))
        frames.shift();
    return frames;
}
function callLocation(fallbackFile) {
    const frames = callFrames();
    if (!frames.length)
        return { file: fallbackFile || '<unknown>', line: 1, column: 1 };
    const location = stackUtils.parseLine(frames[0]);
    return {
        file: path_1.default.resolve(cwd, location.file || ''),
        line: location.line || 0,
        column: location.column || 0,
    };
}
exports.callLocation = callLocation;
function errorWithCallLocation(message) {
    const frames = callFrames();
    const error = new Error(message);
    error.stack = 'Error: ' + message + '\n' + frames.join('\n');
    return error;
}
exports.errorWithCallLocation = errorWithCallLocation;
function monotonicTime() {
    const [seconds, nanoseconds] = process.hrtime();
    return seconds * 1000 + (nanoseconds / 1000000 | 0);
}
exports.monotonicTime = monotonicTime;
function prependErrorMessage(e, message) {
    let stack = e.stack || '';
    if (stack.includes(e.message))
        stack = stack.substring(stack.indexOf(e.message) + e.message.length);
    let m = e.message;
    if (m.startsWith('Error:'))
        m = m.substring('Error:'.length);
    e.message = message + m;
    e.stack = e.message + stack;
}
exports.prependErrorMessage = prependErrorMessage;
function isRegExp(e) {
    return e && typeof e === 'object' && (e instanceof RegExp || Object.prototype.toString.call(e) === '[object RegExp]');
}
exports.isRegExp = isRegExp;
function createMatcher(patterns) {
    const reList = [];
    const filePatterns = [];
    for (const pattern of Array.isArray(patterns) ? patterns : [patterns]) {
        if (isRegExp(pattern)) {
            reList.push(pattern);
        }
        else {
            if (!pattern.startsWith('**/') && !pattern.startsWith('**/'))
                filePatterns.push('**/' + pattern);
            else
                filePatterns.push(pattern);
        }
    }
    return (value) => {
        for (const re of reList) {
            re.lastIndex = 0;
            if (re.test(value))
                return true;
        }
        for (const pattern of filePatterns) {
            if (minimatch_1.default(value, pattern))
                return true;
        }
        return false;
    };
}
exports.createMatcher = createMatcher;
function mergeObjects(a, b) {
    const result = { ...a };
    if (!Object.is(b, undefined)) {
        for (const [name, value] of Object.entries(b)) {
            if (!Object.is(value, undefined))
                result[name] = value;
        }
    }
    return result;
}
exports.mergeObjects = mergeObjects;
async function wrapInPromise(value) {
    return value;
}
exports.wrapInPromise = wrapInPromise;
function formatLocation(location) {
    return location.file + ':' + location.line + ':' + location.column;
}
exports.formatLocation = formatLocation;
function forceRegExp(pattern) {
    const match = pattern.match(/^\/(.*)\/([gi]*)$/);
    if (match)
        return new RegExp(match[1], match[2]);
    return new RegExp(pattern, 'g');
}
exports.forceRegExp = forceRegExp;
//# sourceMappingURL=util.js.map