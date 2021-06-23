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
exports.BrowserType = void 0;
const browser_1 = require("./browser");
const browserContext_1 = require("./browserContext");
const channelOwner_1 = require("./channelOwner");
const ws_1 = __importDefault(require("ws"));
const connection_1 = require("./connection");
const events_1 = require("./events");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const clientHelper_1 = require("./clientHelper");
const utils_1 = require("../utils/utils");
const errors_1 = require("../utils/errors");
class BrowserType extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
    }
    static from(browserType) {
        return browserType._object;
    }
    executablePath() {
        if (!this._initializer.executablePath)
            throw new Error('Browser is not supported on current platform');
        return this._initializer.executablePath;
    }
    name() {
        return this._initializer.name;
    }
    async launch(options = {}) {
        const logger = options.logger;
        return this._wrapApiCall('browserType.launch', async (channel) => {
            utils_1.assert(!options.userDataDir, 'userDataDir option is not supported in `browserType.launch`. Use `browserType.launchPersistentContext` instead');
            utils_1.assert(!options.port, 'Cannot specify a port without launching as a server.');
            const launchOptions = {
                ...options,
                ignoreDefaultArgs: Array.isArray(options.ignoreDefaultArgs) ? options.ignoreDefaultArgs : undefined,
                ignoreAllDefaultArgs: !!options.ignoreDefaultArgs && !Array.isArray(options.ignoreDefaultArgs),
                env: options.env ? clientHelper_1.envObjectToArray(options.env) : undefined,
            };
            const browser = browser_1.Browser.from((await channel.launch(launchOptions)).browser);
            browser._logger = logger;
            return browser;
        }, logger);
    }
    async launchServer(options = {}) {
        if (!this._serverLauncher)
            throw new Error('Launching server is not supported');
        return this._serverLauncher.launchServer(options);
    }
    async launchPersistentContext(userDataDir, options = {}) {
        return this._wrapApiCall('browserType.launchPersistentContext', async (channel) => {
            utils_1.assert(!options.port, 'Cannot specify a port without launching as a server.');
            const contextParams = await browserContext_1.prepareBrowserContextParams(options);
            const persistentParams = {
                ...contextParams,
                ignoreDefaultArgs: Array.isArray(options.ignoreDefaultArgs) ? options.ignoreDefaultArgs : undefined,
                ignoreAllDefaultArgs: !!options.ignoreDefaultArgs && !Array.isArray(options.ignoreDefaultArgs),
                env: options.env ? clientHelper_1.envObjectToArray(options.env) : undefined,
                channel: options.channel,
                userDataDir,
            };
            const result = await channel.launchPersistentContext(persistentParams);
            const context = browserContext_1.BrowserContext.from(result.context);
            context._options = contextParams;
            context._logger = options.logger;
            return context;
        }, options.logger);
    }
    async connect(params) {
        const logger = params.logger;
        const paramsHeaders = Object.assign({ 'User-Agent': utils_1.getUserAgent() }, params.headers);
        return this._wrapApiCall('browserType.connect', async () => {
            const ws = new ws_1.default(params.wsEndpoint, [], {
                perMessageDeflate: false,
                maxPayload: 256 * 1024 * 1024,
                handshakeTimeout: this._timeoutSettings.timeout(params),
                headers: paramsHeaders,
            });
            const connection = new connection_1.Connection(() => ws.close());
            // The 'ws' module in node sometimes sends us multiple messages in a single task.
            const waitForNextTask = params.slowMo
                ? (cb) => setTimeout(cb, params.slowMo)
                : utils_1.makeWaitForNextTask();
            connection.onmessage = message => {
                // Connection should handle all outgoing message in disconnected().
                if (ws.readyState !== ws_1.default.OPEN)
                    return;
                ws.send(JSON.stringify(message));
            };
            ws.addEventListener('message', event => {
                waitForNextTask(() => {
                    try {
                        // Since we may slow down the messages, but disconnect
                        // synchronously, we might come here with a message
                        // after disconnect.
                        if (!connection.isDisconnected())
                            connection.dispatch(JSON.parse(event.data));
                    }
                    catch (e) {
                        console.error(`Playwright: Connection dispatch error`);
                        console.error(e);
                        ws.close();
                    }
                });
            });
            let timeoutCallback = (e) => { };
            const timeoutPromise = new Promise((f, r) => timeoutCallback = r);
            const timer = params.timeout ? setTimeout(() => timeoutCallback(new Error(`Timeout ${params.timeout}ms exceeded.`)), params.timeout) : undefined;
            const successPromise = new Promise(async (fulfill, reject) => {
                if (params.__testHookBeforeCreateBrowser) {
                    try {
                        await params.__testHookBeforeCreateBrowser();
                    }
                    catch (e) {
                        reject(e);
                    }
                }
                ws.addEventListener('open', async () => {
                    const prematureCloseListener = (event) => {
                        reject(new Error(`WebSocket server disconnected (${event.code}) ${event.reason}`));
                    };
                    ws.addEventListener('close', prematureCloseListener);
                    const playwright = await connection.waitForObjectWithKnownName('Playwright');
                    if (!playwright._initializer.preLaunchedBrowser) {
                        reject(new Error('Malformed endpoint. Did you use launchServer method?'));
                        ws.close();
                        return;
                    }
                    const browser = browser_1.Browser.from(playwright._initializer.preLaunchedBrowser);
                    browser._logger = logger;
                    browser._remoteType = 'owns-connection';
                    const closeListener = () => {
                        // Emulate all pages, contexts and the browser closing upon disconnect.
                        for (const context of browser.contexts()) {
                            for (const page of context.pages())
                                page._onClose();
                            context._onClose();
                        }
                        browser._didClose();
                        connection.didDisconnect(errors_1.kBrowserClosedError);
                    };
                    ws.removeEventListener('close', prematureCloseListener);
                    ws.addEventListener('close', closeListener);
                    browser.on(events_1.Events.Browser.Disconnected, () => {
                        playwright._cleanup();
                        ws.removeEventListener('close', closeListener);
                        ws.close();
                    });
                    if (params._forwardPorts) {
                        try {
                            await playwright._enablePortForwarding(params._forwardPorts);
                        }
                        catch (err) {
                            reject(err);
                            return;
                        }
                    }
                    fulfill(browser);
                });
                ws.addEventListener('error', event => {
                    ws.close();
                    reject(new Error(event.message + '. Most likely ws endpoint is incorrect'));
                });
            });
            try {
                return await Promise.race([successPromise, timeoutPromise]);
            }
            finally {
                if (timer)
                    clearTimeout(timer);
            }
        }, logger);
    }
    async connectOverCDP(params) {
        if (this.name() !== 'chromium')
            throw new Error('Connecting over CDP is only supported in Chromium.');
        const logger = params.logger;
        return this._wrapApiCall('browserType.connectOverCDP', async (channel) => {
            const paramsHeaders = Object.assign({ 'User-Agent': utils_1.getUserAgent() }, params.headers);
            const headers = paramsHeaders ? utils_1.headersObjectToArray(paramsHeaders) : undefined;
            const result = await channel.connectOverCDP({
                sdkLanguage: 'javascript',
                endpointURL: 'endpointURL' in params ? params.endpointURL : params.wsEndpoint,
                headers,
                slowMo: params.slowMo,
                timeout: params.timeout
            });
            const browser = browser_1.Browser.from(result.browser);
            if (result.defaultContext)
                browser._contexts.add(browserContext_1.BrowserContext.from(result.defaultContext));
            browser._remoteType = 'uses-connection';
            browser._logger = logger;
            return browser;
        }, logger);
    }
}
exports.BrowserType = BrowserType;
//# sourceMappingURL=browserType.js.map