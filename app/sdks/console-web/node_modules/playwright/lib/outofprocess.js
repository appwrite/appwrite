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
exports.start = void 0;
const connection_1 = require("./client/connection");
const transport_1 = require("./protocol/transport");
const childProcess = __importStar(require("child_process"));
const path = __importStar(require("path"));
async function start() {
    const client = new PlaywrightClient();
    const playwright = await client._playwright;
    playwright.stop = () => client.stop();
    playwright.driverProcess = client._driverProcess;
    return playwright;
}
exports.start = start;
class PlaywrightClient {
    constructor() {
        this._onExit = (exitCode, signal) => {
            throw new Error(`Server closed with exitCode=${exitCode} signal=${signal}`);
        };
        this._driverProcess = childProcess.fork(path.join(__dirname, 'cli', 'cli.js'), ['run-driver'], {
            stdio: 'pipe',
            detached: true,
        });
        this._driverProcess.unref();
        this._driverProcess.on('exit', this._onExit);
        const connection = new connection_1.Connection();
        const transport = new transport_1.Transport(this._driverProcess.stdin, this._driverProcess.stdout);
        connection.onmessage = message => transport.send(JSON.stringify(message));
        transport.onmessage = message => connection.dispatch(JSON.parse(message));
        this._closePromise = new Promise(f => transport.onclose = f);
        this._playwright = connection.waitForObjectWithKnownName('Playwright');
    }
    async stop() {
        this._driverProcess.removeListener('exit', this._onExit);
        this._driverProcess.stdin.destroy();
        this._driverProcess.stdout.destroy();
        this._driverProcess.stderr.destroy();
        await this._closePromise;
    }
}
//# sourceMappingURL=outofprocess.js.map