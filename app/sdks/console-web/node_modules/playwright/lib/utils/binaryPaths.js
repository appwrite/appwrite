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
exports.printDepsWindowsExecutable = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
function printDepsWindowsExecutable() {
    return pathToExecutable(['bin', 'PrintDeps.exe']);
}
exports.printDepsWindowsExecutable = printDepsWindowsExecutable;
function pathToExecutable(relative) {
    try {
        const defaultPath = path_1.default.join(__dirname, '..', '..', ...relative);
        if (fs_1.default.existsSync(defaultPath))
            return defaultPath;
    }
    catch (e) {
    }
}
//# sourceMappingURL=binaryPaths.js.map