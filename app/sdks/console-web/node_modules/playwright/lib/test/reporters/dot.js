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
class DotReporter extends base_1.BaseReporter {
    constructor() {
        super(...arguments);
        this._counter = 0;
    }
    onTestEnd(test, result) {
        super.onTestEnd(test, result);
        if (++this._counter === 81) {
            process.stdout.write('\n');
            return;
        }
        if (result.status === 'skipped') {
            process.stdout.write(safe_1.default.yellow('°'));
            return;
        }
        if (this.willRetry(test, result)) {
            process.stdout.write(safe_1.default.gray('×'));
            return;
        }
        switch (test.status()) {
            case 'expected':
                process.stdout.write(safe_1.default.green('·'));
                break;
            case 'unexpected':
                process.stdout.write(safe_1.default.red(test.results[test.results.length - 1].status === 'timedOut' ? 'T' : 'F'));
                break;
            case 'flaky':
                process.stdout.write(safe_1.default.yellow('±'));
                break;
        }
    }
    onTimeout(timeout) {
        super.onTimeout(timeout);
        this.onEnd();
    }
    onEnd() {
        super.onEnd();
        process.stdout.write('\n');
        this.epilogue(true);
    }
}
exports.default = DotReporter;
//# sourceMappingURL=dot.js.map