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
exports.CDPSessionDispatcher = void 0;
const crConnection_1 = require("../server/chromium/crConnection");
const dispatcher_1 = require("./dispatcher");
class CDPSessionDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, crSession) {
        super(scope, crSession, 'CDPSession', {}, true);
        crSession._eventListener = (method, params) => {
            this._dispatchEvent('event', { method, params });
        };
        crSession.on(crConnection_1.CRSessionEvents.Disconnected, () => this._dispose());
    }
    async send(params) {
        return { result: await this._object.send(params.method, params.params) };
    }
    async detach() {
        return this._object.detach();
    }
}
exports.CDPSessionDispatcher = CDPSessionDispatcher;
//# sourceMappingURL=cdpSessionDispatcher.js.map