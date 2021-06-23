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
exports.SocksSocket = void 0;
const net_1 = __importDefault(require("net"));
const playwright_1 = require("./playwright");
const utils_1 = require("../utils/utils");
const channelOwner_1 = require("./channelOwner");
class SocksSocket extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        utils_1.assert(parent instanceof playwright_1.Playwright);
        utils_1.assert(parent._forwardPorts.includes(this._initializer.dstPort));
        utils_1.assert(utils_1.isLocalIpAddress(this._initializer.dstAddr));
        if (utils_1.isUnderTest() && process.env.PW_TEST_PROXY_TARGET)
            this._initializer.dstPort = Number(process.env.PW_TEST_PROXY_TARGET);
        this._socket = net_1.default.createConnection(this._initializer.dstPort, this._initializer.dstAddr);
        this._socket.on('error', (err) => this._channel.error({ error: String(err) }));
        this._socket.on('connect', () => {
            this.connected().catch(() => { });
            this._socket.on('data', data => this.write(data).catch(() => { }));
        });
        this._socket.on('close', () => {
            this.end().catch(() => { });
        });
        this._channel.on('data', ({ data }) => {
            if (!this._socket.writable)
                return;
            this._socket.write(Buffer.from(data, 'base64'));
        });
        this._channel.on('close', () => this._socket.end());
        this._connection.on('disconnect', () => this._socket.end());
    }
    static from(socket) {
        return socket._object;
    }
    async write(data) {
        await this._channel.write({ data: data.toString('base64') });
    }
    async end() {
        await this._channel.end();
    }
    async connected() {
        await this._channel.connected();
    }
}
exports.SocksSocket = SocksSocket;
//# sourceMappingURL=socksSocket.js.map