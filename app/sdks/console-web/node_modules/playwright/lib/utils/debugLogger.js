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
exports.RecentLogsCollector = exports.debugLogger = void 0;
const debug_1 = __importDefault(require("debug"));
const fs_1 = __importDefault(require("fs"));
const debugLoggerColorMap = {
    'api': 45,
    'protocol': 34,
    'install': 34,
    'browser': 0,
    'proxy': 92,
    'error': 160,
    'channel:command': 33,
    'channel:response': 202,
    'channel:event': 207, // magenta
};
class DebugLogger {
    constructor() {
        this._debuggers = new Map();
        if (process.env.DEBUG_FILE) {
            const ansiRegex = new RegExp([
                '[\\u001B\\u009B][[\\]()#;?]*(?:(?:(?:[a-zA-Z\\d]*(?:;[-a-zA-Z\\d\\/#&.:=?%@~_]*)*)?\\u0007)',
                '(?:(?:\\d{1,4}(?:;\\d{0,4})*)?[\\dA-PR-TZcf-ntqry=><~]))'
            ].join('|'), 'g');
            const stream = fs_1.default.createWriteStream(process.env.DEBUG_FILE);
            debug_1.default.log = (data) => {
                stream.write(data.replace(ansiRegex, ''));
                stream.write('\n');
            };
        }
    }
    log(name, message) {
        let cachedDebugger = this._debuggers.get(name);
        if (!cachedDebugger) {
            cachedDebugger = debug_1.default(`pw:${name}`);
            this._debuggers.set(name, cachedDebugger);
            cachedDebugger.color = debugLoggerColorMap[name];
        }
        cachedDebugger(message);
    }
    isEnabled(name) {
        return debug_1.default.enabled(`pw:${name}`);
    }
}
exports.debugLogger = new DebugLogger();
const kLogCount = 50;
class RecentLogsCollector {
    constructor() {
        this._logs = [];
    }
    log(message) {
        this._logs.push(message);
        if (this._logs.length === kLogCount * 2)
            this._logs.splice(0, kLogCount);
    }
    recentLogs() {
        if (this._logs.length > kLogCount)
            return this._logs.slice(-kLogCount);
        return this._logs;
    }
}
exports.RecentLogsCollector = RecentLogsCollector;
//# sourceMappingURL=debugLogger.js.map