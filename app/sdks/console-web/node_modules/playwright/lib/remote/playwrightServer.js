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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.PlaywrightServer = void 0;
const debug_1 = __importDefault(require("debug"));
const http = __importStar(require("http"));
const ws = __importStar(require("ws"));
const dispatcher_1 = require("../dispatchers/dispatcher");
const playwrightDispatcher_1 = require("../dispatchers/playwrightDispatcher");
const playwright_1 = require("../server/playwright");
const processLauncher_1 = require("../server/processLauncher");
const selectors_1 = require("../server/selectors");
const debugLog = debug_1.default('pw:server');
class PlaywrightServer {
    constructor(delegate) {
        this._clientsCount = 0;
        this._delegate = delegate;
    }
    static async startDefault({ acceptForwardedPorts } = {}) {
        const cleanup = async () => {
            await processLauncher_1.gracefullyCloseAll().catch(e => { });
            selectors_1.serverSelectors.unregisterAll();
        };
        const delegate = {
            path: '/ws',
            allowMultipleClients: false,
            onClose: cleanup,
            onConnect: async (rootScope) => {
                const playwright = playwright_1.createPlaywright();
                if (acceptForwardedPorts)
                    await playwright._enablePortForwarding();
                new playwrightDispatcher_1.PlaywrightDispatcher(rootScope, playwright);
                return () => {
                    cleanup();
                    playwright._disablePortForwarding();
                };
            },
        };
        return new PlaywrightServer(delegate);
    }
    async listen(port = 0) {
        const server = http.createServer((request, response) => {
            response.end('Running');
        });
        server.on('error', error => debugLog(error));
        const path = this._delegate.path;
        const wsEndpoint = await new Promise((resolve, reject) => {
            server.listen(port, () => {
                const address = server.address();
                const wsEndpoint = typeof address === 'string' ? `${address}${path}` : `ws://127.0.0.1:${address.port}${path}`;
                resolve(wsEndpoint);
            }).on('error', reject);
        });
        debugLog('Listening at ' + wsEndpoint);
        this._wsServer = new ws.Server({ server, path });
        this._wsServer.on('connection', async (socket) => {
            if (this._clientsCount && !this._delegate.allowMultipleClients) {
                socket.close();
                return;
            }
            this._clientsCount++;
            debugLog('Incoming connection');
            const connection = new dispatcher_1.DispatcherConnection();
            connection.onmessage = message => {
                if (socket.readyState !== ws.CLOSING)
                    socket.send(JSON.stringify(message));
            };
            socket.on('message', (message) => {
                connection.dispatch(JSON.parse(Buffer.from(message).toString()));
            });
            const forceDisconnect = () => socket.close();
            const scope = connection.rootDispatcher();
            let onDisconnect = () => { };
            const disconnected = () => {
                this._clientsCount--;
                // Avoid sending any more messages over closed socket.
                connection.onmessage = () => { };
                onDisconnect();
            };
            socket.on('close', () => {
                debugLog('Client closed');
                disconnected();
            });
            socket.on('error', error => {
                debugLog('Client error ' + error);
                disconnected();
            });
            onDisconnect = await this._delegate.onConnect(scope, forceDisconnect);
        });
        return wsEndpoint;
    }
    async close() {
        if (!this._wsServer)
            return;
        debugLog('Closing server');
        // First disconnect all remaining clients.
        await new Promise(f => this._wsServer.close(f));
        await new Promise(f => this._wsServer.options.server.close(f));
        this._wsServer = undefined;
        await this._delegate.onClose();
    }
}
exports.PlaywrightServer = PlaywrightServer;
//# sourceMappingURL=playwrightServer.js.map