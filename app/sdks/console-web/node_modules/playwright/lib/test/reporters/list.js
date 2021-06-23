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
// @ts-ignore
const ms_1 = __importDefault(require("ms"));
const base_1 = require("./base");
class ListReporter extends base_1.BaseReporter {
    constructor() {
        super(...arguments);
        this._failure = 0;
        this._lastRow = 0;
        this._testRows = new Map();
        this._needNewLine = false;
    }
    onBegin(config, suite) {
        super.onBegin(config, suite);
        console.log();
    }
    onTestBegin(test) {
        super.onTestBegin(test);
        if (process.stdout.isTTY) {
            if (this._needNewLine) {
                this._needNewLine = false;
                process.stdout.write('\n');
                this._lastRow++;
            }
            process.stdout.write('    ' + safe_1.default.gray(base_1.formatTestTitle(this.config, test) + ': ') + '\n');
        }
        this._testRows.set(test, this._lastRow++);
    }
    onStdOut(chunk, test) {
        this._dumpToStdio(test, chunk, process.stdout);
    }
    onStdErr(chunk, test) {
        this._dumpToStdio(test, chunk, process.stdout);
    }
    _dumpToStdio(test, chunk, stream) {
        if (this.config.quiet)
            return;
        const text = chunk.toString('utf-8');
        this._needNewLine = text[text.length - 1] !== '\n';
        if (process.stdout.isTTY) {
            const newLineCount = text.split('\n').length - 1;
            this._lastRow += newLineCount;
        }
        stream.write(chunk);
    }
    onTestEnd(test, result) {
        super.onTestEnd(test, result);
        const duration = safe_1.default.dim(` (${ms_1.default(result.duration)})`);
        const title = base_1.formatTestTitle(this.config, test);
        let text = '';
        if (result.status === 'skipped') {
            text = safe_1.default.green('  - ') + safe_1.default.cyan(title);
        }
        else {
            const statusMark = result.status === 'passed' ? '  ✓ ' : '  x ';
            if (result.status === test.expectedStatus)
                text = '\u001b[2K\u001b[0G' + safe_1.default.green(statusMark) + safe_1.default.gray(title) + duration;
            else
                text = '\u001b[2K\u001b[0G' + safe_1.default.red(`${statusMark}${++this._failure}) ` + title) + duration;
        }
        const testRow = this._testRows.get(test);
        // Go up if needed
        if (process.stdout.isTTY && testRow !== this._lastRow)
            process.stdout.write(`\u001B[${this._lastRow - testRow}A`);
        // Erase line
        if (process.stdout.isTTY)
            process.stdout.write('\u001B[2K');
        if (!process.stdout.isTTY && this._needNewLine) {
            this._needNewLine = false;
            process.stdout.write('\n');
        }
        process.stdout.write(text);
        // Go down if needed.
        if (testRow !== this._lastRow) {
            if (process.stdout.isTTY)
                process.stdout.write(`\u001B[${this._lastRow - testRow}E`);
            else
                process.stdout.write('\n');
        }
    }
    onEnd() {
        super.onEnd();
        process.stdout.write('\n');
        this.epilogue(true);
    }
}
exports.default = ListReporter;
//# sourceMappingURL=list.js.map