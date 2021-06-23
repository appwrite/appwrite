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
exports.BrowserServerLauncherImpl = void 0;
const browser_1 = require("./server/browser");
const ws_1 = require("ws");
const dispatcher_1 = require("./dispatchers/dispatcher");
const browserContextDispatcher_1 = require("./dispatchers/browserContextDispatcher");
const clientHelper_1 = require("./client/clientHelper");
const utils_1 = require("./utils/utils");
const selectorsDispatcher_1 = require("./dispatchers/selectorsDispatcher");
const selectors_1 = require("./server/selectors");
const instrumentation_1 = require("./server/instrumentation");
const playwright_1 = require("./server/playwright");
const playwrightDispatcher_1 = require("./dispatchers/playwrightDispatcher");
const playwrightServer_1 = require("./remote/playwrightServer");
const browserContext_1 = require("./server/browserContext");
const cdpSessionDispatcher_1 = require("./dispatchers/cdpSessionDispatcher");
class BrowserServerLauncherImpl {
    constructor(browserName) {
        this._browserName = browserName;
    }
    async launchServer(options = {}) {
        const playwright = playwright_1.createPlaywright();
        if (options._acceptForwardedPorts)
            await playwright._enablePortForwarding();
        // 1. Pre-launch the browser
        const browser = await playwright[this._browserName].launch(instrumentation_1.internalCallMetadata(), {
            ...options,
            ignoreDefaultArgs: Array.isArray(options.ignoreDefaultArgs) ? options.ignoreDefaultArgs : undefined,
            ignoreAllDefaultArgs: !!options.ignoreDefaultArgs && !Array.isArray(options.ignoreDefaultArgs),
            env: options.env ? clientHelper_1.envObjectToArray(options.env) : undefined,
        }, toProtocolLogger(options.logger));
        // 2. Start the server
        const delegate = {
            path: '/' + utils_1.createGuid(),
            allowMultipleClients: options._acceptForwardedPorts ? false : true,
            onClose: () => {
                playwright._disablePortForwarding();
            },
            onConnect: this._onConnect.bind(this, playwright, browser),
        };
        const server = new playwrightServer_1.PlaywrightServer(delegate);
        const wsEndpoint = await server.listen(options.port);
        // 3. Return the BrowserServer interface
        const browserServer = new ws_1.EventEmitter();
        browserServer.process = () => browser.options.browserProcess.process;
        browserServer.wsEndpoint = () => wsEndpoint;
        browserServer.close = () => browser.options.browserProcess.close();
        browserServer.kill = () => browser.options.browserProcess.kill();
        browserServer._disconnectForTest = () => server.close();
        browser.options.browserProcess.onclose = async (exitCode, signal) => {
            server.close();
            browserServer.emit('close', exitCode, signal);
        };
        return browserServer;
    }
    async _onConnect(playwright, browser, scope, forceDisconnect) {
        const selectors = new selectors_1.Selectors();
        const selectorsDispatcher = new selectorsDispatcher_1.SelectorsDispatcher(scope, selectors);
        const browserDispatcher = new ConnectedBrowserDispatcher(scope, browser, selectors);
        browser.on(browser_1.Browser.Events.Disconnected, () => {
            // Underlying browser did close for some reason - force disconnect the client.
            forceDisconnect();
        });
        new playwrightDispatcher_1.PlaywrightDispatcher(scope, playwright, selectorsDispatcher, browserDispatcher);
        return () => {
            // Cleanup contexts upon disconnect.
            browserDispatcher.cleanupContexts().catch(e => { });
        };
    }
}
exports.BrowserServerLauncherImpl = BrowserServerLauncherImpl;
// This class implements multiplexing browser dispatchers over a single Browser instance.
class ConnectedBrowserDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, browser, selectors) {
        super(scope, browser, 'Browser', { version: browser.version(), name: browser.options.name }, true);
        this._contexts = new Set();
        this._selectors = selectors;
    }
    async newContext(params, metadata) {
        if (params.recordVideo)
            params.recordVideo.dir = this._object.options.artifactsDir;
        const context = await this._object.newContext(params);
        this._contexts.add(context);
        context._setSelectors(this._selectors);
        context.on(browserContext_1.BrowserContext.Events.Close, () => this._contexts.delete(context));
        if (params.storageState)
            await context.setStorageState(metadata, params.storageState);
        return { context: new browserContextDispatcher_1.BrowserContextDispatcher(this._scope, context) };
    }
    async close() {
        // Client should not send us Browser.close.
    }
    async killForTests() {
        // Client should not send us Browser.killForTests.
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
    async cleanupContexts() {
        await Promise.all(Array.from(this._contexts).map(context => context.close(instrumentation_1.internalCallMetadata())));
    }
}
function toProtocolLogger(logger) {
    return logger ? (direction, message) => {
        if (logger.isEnabled('protocol', 'verbose'))
            logger.log('protocol', 'verbose', (direction === 'send' ? 'SEND ► ' : '◀ RECV ') + JSON.stringify(message), [], {});
    } : undefined;
}
//# sourceMappingURL=browserServerImpl.js.map