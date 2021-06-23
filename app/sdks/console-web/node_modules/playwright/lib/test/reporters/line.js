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
const safe_1 = __importDefault(require("colors/safe"));
const base_1 = require("./base");
class LineReporter extends base_1.BaseReporter {
    constructor() {
        super(...arguments);
        this._total = 0;
        this._current = 0;
        this._failures = 0;
    }
    onBegin(config, suite) {
        super.onBegin(config, suite);
        this._total = suite.totalTestCount();
        console.log();
    }
    onStdOut(chunk, test) {
        this._dumpToStdio(test, chunk, process.stdout);
    }
    onStdErr(chunk, test) {
        this._dumpToStdio(test, chunk, process.stderr);
    }
    _dumpToStdio(test, chunk, stream) {
        if (this.config.quiet)
            return;
        stream.write(`\u001B[1A\u001B[2K`);
        if (test && this._lastTest !== test) {
            // Write new header for the output.
            stream.write(safe_1.default.gray(base_1.formatTestTitle(this.config, test) + `\n`));
            this._lastTest = test;
        }
        stream.write(chunk);
        console.log();
    }
    onTestEnd(test, result) {
        super.onTestEnd(test, result);
        const width = process.stdout.columns - 1;
        const title = `[${++this._current}/${this._total}] ${base_1.formatTestTitle(this.config, test)}`.substring(0, width);
        process.stdout.write(`\u001B[1A\u001B[2K${title}\n`);
        if (!this.willRetry(test, result) && !test.ok()) {
            process.stdout.write(`\u001B[1A\u001B[2K`);
            console.log(base_1.formatFailure(this.config, test, ++this._failures));
            console.log();
        }
    }
    onEnd() {
        process.stdout.write(`\u001B[1A\u001B[2K`);
        super.onEnd();
        this.epilogue(false);
    }
}
exports.default = LineReporter;
//# sourceMappingURL=line.js.map