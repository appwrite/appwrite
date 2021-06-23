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
exports.Waiter = void 0;
const stackTrace_1 = require("../utils/stackTrace");
const errors_1 = require("../utils/errors");
const utils_1 = require("../utils/utils");
class Waiter {
    constructor(channelOwner, apiName) {
        this._failures = [];
        // TODO: can/should we move these logs into wrapApiCall?
        this._logs = [];
        this._waitId = utils_1.createGuid();
        this._channelOwner = channelOwner;
        this._channelOwner._waitForEventInfoBefore(this._waitId, apiName);
        this._dispose = [
            () => this._channelOwner._waitForEventInfoAfter(this._waitId, this._error)
        ];
    }
    static createForEvent(channelOwner, target, event) {
        return new Waiter(channelOwner, `${target}.waitForEvent(${event})`);
    }
    async waitForEvent(emitter, event, predicate) {
        const { promise, dispose } = waitForEvent(emitter, event, predicate);
        return this.waitForPromise(promise, dispose);
    }
    rejectOnEvent(emitter, event, error, predicate) {
        const { promise, dispose } = waitForEvent(emitter, event, predicate);
        this._rejectOn(promise.then(() => { throw error; }), dispose);
    }
    rejectOnTimeout(timeout, message) {
        if (!timeout)
            return;
        const { promise, dispose } = waitForTimeout(timeout);
        this._rejectOn(promise.then(() => { throw new errors_1.TimeoutError(message); }), dispose);
    }
    rejectImmediately(error) {
        this._immediateError = error;
    }
    dispose() {
        for (const dispose of this._dispose)
            dispose();
    }
    async waitForPromise(promise, dispose) {
        try {
            if (this._immediateError)
                throw this._immediateError;
            const result = await Promise.race([promise, ...this._failures]);
            if (dispose)
                dispose();
            return result;
        }
        catch (e) {
            if (dispose)
                dispose();
            this._error = e.message;
            this.dispose();
            stackTrace_1.rewriteErrorMessage(e, e.message + formatLogRecording(this._logs) + kLoggingNote);
            throw e;
        }
    }
    log(s) {
        this._logs.push(s);
        this._channelOwner._waitForEventInfoLog(this._waitId, s);
    }
    _rejectOn(promise, dispose) {
        this._failures.push(promise);
        if (dispose)
            this._dispose.push(dispose);
    }
}
exports.Waiter = Waiter;
function waitForEvent(emitter, event, predicate) {
    let listener;
    const promise = new Promise((resolve, reject) => {
        listener = async (eventArg) => {
            try {
                if (predicate && !(await predicate(eventArg)))
                    return;
                emitter.removeListener(event, listener);
                resolve(eventArg);
            }
            catch (e) {
                emitter.removeListener(event, listener);
                reject(e);
            }
        };
        emitter.addListener(event, listener);
    });
    const dispose = () => emitter.removeListener(event, listener);
    return { promise, dispose };
}
function waitForTimeout(timeout) {
    let timeoutId;
    const promise = new Promise(resolve => timeoutId = setTimeout(resolve, timeout));
    const dispose = () => clearTimeout(timeoutId);
    return { promise, dispose };
}
const kLoggingNote = `\nNote: use DEBUG=pw:api environment variable to capture Playwright logs.`;
function formatLogRecording(log) {
    if (!log.length)
        return '';
    const header = ` logs `;
    const headerLength = 60;
    const leftLength = (headerLength - header.length) / 2;
    const rightLength = headerLength - header.length - leftLength;
    return `\n${'='.repeat(leftLength)}${header}${'='.repeat(rightLength)}\n${log.join('\n')}\n${'='.repeat(headerLength)}`;
}
//# sourceMappingURL=waiter.js.map