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
exports.Multiplexer = void 0;
class Multiplexer {
    constructor(reporters) {
        this._reporters = reporters;
    }
    onBegin(config, suite) {
        for (const reporter of this._reporters)
            reporter.onBegin(config, suite);
    }
    onTestBegin(test) {
        for (const reporter of this._reporters)
            reporter.onTestBegin(test);
    }
    onStdOut(chunk, test) {
        for (const reporter of this._reporters)
            reporter.onStdOut(chunk, test);
    }
    onStdErr(chunk, test) {
        for (const reporter of this._reporters)
            reporter.onStdErr(chunk, test);
    }
    onTestEnd(test, result) {
        for (const reporter of this._reporters)
            reporter.onTestEnd(test, result);
    }
    onTimeout(timeout) {
        for (const reporter of this._reporters)
            reporter.onTimeout(timeout);
    }
    onEnd() {
        for (const reporter of this._reporters)
            reporter.onEnd();
    }
    onError(error) {
        for (const reporter of this._reporters)
            reporter.onError(error);
    }
}
exports.Multiplexer = Multiplexer;
//# sourceMappingURL=multiplexer.js.map