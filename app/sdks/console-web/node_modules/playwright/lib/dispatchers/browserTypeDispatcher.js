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
exports.BrowserTypeDispatcher = void 0;
const browserDispatcher_1 = require("./browserDispatcher");
const dispatcher_1 = require("./dispatcher");
const browserContextDispatcher_1 = require("./browserContextDispatcher");
class BrowserTypeDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, browserType) {
        super(scope, browserType, 'BrowserType', {
            executablePath: browserType.executablePath(),
            name: browserType.name()
        }, true);
    }
    async launch(params, metadata) {
        const browser = await this._object.launch(metadata, params);
        return { browser: new browserDispatcher_1.BrowserDispatcher(this._scope, browser) };
    }
    async launchPersistentContext(params, metadata) {
        const browserContext = await this._object.launchPersistentContext(metadata, params.userDataDir, params);
        return { context: new browserContextDispatcher_1.BrowserContextDispatcher(this._scope, browserContext) };
    }
    async connectOverCDP(params, metadata) {
        const browser = await this._object.connectOverCDP(metadata, params.endpointURL, params, params.timeout);
        return {
            browser: new browserDispatcher_1.BrowserDispatcher(this._scope, browser),
            defaultContext: browser._defaultContext ? new browserContextDispatcher_1.BrowserContextDispatcher(this._scope, browser._defaultContext) : undefined,
        };
    }
}
exports.BrowserTypeDispatcher = BrowserTypeDispatcher;
//# sourceMappingURL=browserTypeDispatcher.js.map