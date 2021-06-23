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
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
const console_1 = require("console");
const util = __importStar(require("util"));
const util_1 = require("./util");
const workerRunner_1 = require("./workerRunner");
let closed = false;
sendMessageToParent('ready');
global.console = new console_1.Console({
    stdout: process.stdout,
    stderr: process.stderr,
    colorMode: process.env.FORCE_COLOR === '1',
});
process.stdout.write = (chunk) => {
    var _a;
    const outPayload = {
        testId: (_a = workerRunner === null || workerRunner === void 0 ? void 0 : workerRunner._currentTest) === null || _a === void 0 ? void 0 : _a.testId,
        ...chunkToParams(chunk)
    };
    sendMessageToParent('stdOut', outPayload);
    return true;
};
if (!process.env.PW_RUNNER_DEBUG) {
    process.stderr.write = (chunk) => {
        var _a;
        const outPayload = {
            testId: (_a = workerRunner === null || workerRunner === void 0 ? void 0 : workerRunner._currentTest) === null || _a === void 0 ? void 0 : _a.testId,
            ...chunkToParams(chunk)
        };
        sendMessageToParent('stdErr', outPayload);
        return true;
    };
}
process.on('disconnect', gracefullyCloseAndExit);
process.on('SIGINT', () => { });
process.on('SIGTERM', () => { });
let workerRunner;
process.on('unhandledRejection', (reason, promise) => {
    if (workerRunner)
        workerRunner.unhandledError(reason);
});
process.on('uncaughtException', error => {
    if (workerRunner)
        workerRunner.unhandledError(error);
});
process.on('message', async (message) => {
    if (message.method === 'init') {
        const initParams = message.params;
        workerRunner = new workerRunner_1.WorkerRunner(initParams);
        for (const event of ['testBegin', 'testEnd', 'done'])
            workerRunner.on(event, sendMessageToParent.bind(null, event));
        return;
    }
    if (message.method === 'stop') {
        await gracefullyCloseAndExit();
        return;
    }
    if (message.method === 'run') {
        const runPayload = message.params;
        await workerRunner.run(runPayload);
    }
});
async function gracefullyCloseAndExit() {
    if (closed)
        return;
    closed = true;
    // Force exit after 30 seconds.
    setTimeout(() => process.exit(0), 30000);
    // Meanwhile, try to gracefully shutdown.
    try {
        if (workerRunner) {
            workerRunner.stop();
            await workerRunner.cleanup();
        }
    }
    catch (e) {
        process.send({ method: 'teardownError', params: { error: util_1.serializeError(e) } });
    }
    process.exit(0);
}
function sendMessageToParent(method, params = {}) {
    try {
        process.send({ method, params });
    }
    catch (e) {
        // Can throw when closing.
    }
}
function chunkToParams(chunk) {
    if (chunk instanceof Buffer)
        return { buffer: chunk.toString('base64') };
    if (typeof chunk !== 'string')
        return { text: util.inspect(chunk) };
    return { text: chunk };
}
//# sourceMappingURL=worker.js.map