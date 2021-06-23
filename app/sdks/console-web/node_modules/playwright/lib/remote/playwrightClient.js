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
exports.PlaywrightClient = void 0;
const ws_1 = __importDefault(require("ws"));
const connection_1 = require("../client/connection");
class PlaywrightClient {
    constructor(playwright, ws) {
        this._playwright = playwright;
        this._ws = ws;
        this._closePromise = new Promise(f => ws.on('close', f));
    }
    static async connect(options) {
        const { wsEndpoint, forwardPorts, timeout = 30000 } = options;
        const connection = new connection_1.Connection();
        const ws = new ws_1.default(wsEndpoint);
        connection.onmessage = message => ws.send(JSON.stringify(message));
        ws.on('message', message => connection.dispatch(JSON.parse(message.toString())));
        const errorPromise = new Promise((_, reject) => ws.on('error', error => reject(error)));
        const closePromise = new Promise((_, reject) => ws.on('close', () => reject(new Error('Connection closed'))));
        const playwrightClientPromise = new Promise(async (resolve, reject) => {
            const playwright = await connection.waitForObjectWithKnownName('Playwright');
            if (forwardPorts)
                await playwright._enablePortForwarding(forwardPorts).catch(reject);
            resolve(new PlaywrightClient(playwright, ws));
        });
        let timer;
        try {
            await Promise.race([
                playwrightClientPromise,
                errorPromise,
                closePromise,
                new Promise((_, reject) => timer = setTimeout(reject, timeout))
            ]);
            return await playwrightClientPromise;
        }
        finally {
            clearTimeout(timer);
        }
    }
    playwright() {
        return this._playwright;
    }
    async close() {
        this._ws.close();
        await this._closePromise;
    }
}
exports.PlaywrightClient = PlaywrightClient;
//# sourceMappingURL=playwrightClient.js.map