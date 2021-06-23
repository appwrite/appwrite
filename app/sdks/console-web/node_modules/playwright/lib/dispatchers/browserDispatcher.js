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
exports.BrowserDispatcher = void 0;
const browser_1 = require("../server/browser");
const browserContextDispatcher_1 = require("./browserContextDispatcher");
const cdpSessionDispatcher_1 = require("./cdpSessionDispatcher");
const dispatcher_1 = require("./dispatcher");
class BrowserDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, browser) {
        super(scope, browser, 'Browser', { version: browser.version(), name: browser.options.name }, true);
        browser.on(browser_1.Browser.Events.Disconnected, () => this._didClose());
    }
    _didClose() {
        this._dispatchEvent('close');
        this._dispose();
    }
    async newContext(params, metadata) {
        const context = await this._object.newContext(params);
        if (params.storageState)
            await context.setStorageState(metadata, params.storageState);
        return { context: new browserContextDispatcher_1.BrowserContextDispatcher(this._scope, context) };
    }
    async close() {
        await this._object.close();
    }
    async killForTests() {
        await this._object.killForTests();
    }
    async newBrowserCDPSession() {
        if (!this._object.options.isChromium)
            throw new Error(`CDP session is only available in Chromium`);
        const crBrowser = this._object;
        return { session: new cdpSessionDispatcher_1.CDPSessionDispatcher(this._scope, await crBrowser.newBrowserCDPSession()) };
    }
    async startTracing(params) {
        if (!this._object.options.isChromium)
            throw new Error(`Tracing is only available in Chromium`);
        const crBrowser = this._object;
        await crBrowser.startTracing(params.page ? params.page._object : undefined, params);
    }
    async stopTracing() {
        if (!this._object.options.isChromium)
            throw new Error(`Tracing is only available in Chromium`);
        const crBrowser = this._object;
        const buffer = await crBrowser.stopTracing();
        return { binary: buffer.toString('base64') };
    }
}
exports.BrowserDispatcher = BrowserDispatcher;
//# sourceMappingURL=browserDispatcher.js.map