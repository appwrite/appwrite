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
exports.expect = void 0;
const expect_1 = __importDefault(require("expect"));
const globals_1 = require("./globals");
const golden_1 = require("./golden");
exports.expect = expect_1.default;
function toMatchSnapshot(received, nameOrOptions, optOptions = {}) {
    let options;
    const testInfo = globals_1.currentTestInfo();
    if (!testInfo)
        throw new Error(`toMatchSnapshot() must be called during the test`);
    if (typeof nameOrOptions === 'string')
        options = { name: nameOrOptions, ...optOptions };
    else
        options = { ...nameOrOptions };
    if (!options.name)
        throw new Error(`toMatchSnapshot() requires a "name" parameter`);
    const { pass, message } = golden_1.compare(received, options.name, testInfo.snapshotPath, testInfo.outputPath, testInfo.config.updateSnapshots, options);
    return { pass, message: () => message };
}
expect_1.default.extend({ toMatchSnapshot });
//# sourceMappingURL=expect.js.map