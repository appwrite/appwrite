"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
Object.defineProperty(exports, "__esModule", { value: true });
exports.SocksSocketDispatcher = void 0;
const dispatcher_1 = require("./dispatcher");
class SocksSocketDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, socket) {
        super(scope, socket, 'SocksSocket', {
            dstAddr: socket.dstAddr,
            dstPort: socket.dstPort
        }, true);
        socket.on('data', (data) => this._dispatchEvent('data', { data: data.toString('base64') }));
        socket.on('close', () => {
            this._dispatchEvent('close');
            this._dispose();
        });
    }
    async connected() {
        this._object.connected();
    }
    async error(params) {
        this._object.error(params.error);
    }
    async write(params) {
        this._object.write(Buffer.from(params.data, 'base64'));
    }
    async end() {
        this._object.end();
    }
}
exports.SocksSocketDispatcher = SocksSocketDispatcher;
//# sourceMappingURL=socksSocketDispatcher.js.map