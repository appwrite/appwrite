"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
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
exports.normalizeProxySettings = exports.verifyGeolocation = exports.validateBrowserContextOptions = exports.assertBrowserContextIsNotOwned = exports.BrowserContext = void 0;
const os = __importStar(require("os"));
const timeoutSettings_1 = require("../utils/timeoutSettings");
const utils_1 = require("../utils/utils");
const helper_1 = require("./helper");
const network = __importStar(require("./network"));
const page_1 = require("./page");
const selectors_1 = require("./selectors");
const path_1 = __importDefault(require("path"));
const instrumentation_1 = require("./instrumentation");
const debugger_1 = require("./supplements/debugger");
const tracing_1 = require("./trace/recorder/tracing");
const harTracer_1 = require("./supplements/har/harTracer");
const recorderSupplement_1 = require("./supplements/recorderSupplement");
const consoleApiSource = __importStar(require("../generated/consoleApiSource"));
class BrowserContext extends instrumentation_1.SdkObject {
    constructor(browser, options, browserContextId) {
        super(browser, 'browser-context');
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
        this._pageBindings = new Map();
        this._closedStatus = 'open';
        this._permissions = new Map();
        this._downloads = new Set();
        this._origins = new Set();
        this.attribution.context = this;
        this._browser = browser;
        this._options = options;
        this._browserContextId = browserContextId;
        this._isPersistentContext = !browserContextId;
        this._closePromise = new Promise(fulfill => this._closePromiseFulfill = fulfill);
        if (this._options.recordHar)
            this._harTracer = new harTracer_1.HarTracer(this, this._options.recordHar);
        this.tracing = new tracing_1.Tracing(this);
    }
    _setSelectors(selectors) {
        this._selectors = selectors;
    }
    selectors() {
        return this._selectors || selectors_1.serverSelectors;
    }
    async _initialize() {
        if (this.attribution.isInternal)
            return;
        // Create instrumentation per context.
        this.instrumentation = instrumentation_1.createInstrumentation();
        // Debugger will pause execution upon page.pause in headed mode.
        const contextDebugger = new debugger_1.Debugger(this);
        this.instrumentation.addListener(contextDebugger);
        // When PWDEBUG=1, show inspector for each context.
        if (utils_1.debugMode() === 'inspector')
            await recorderSupplement_1.RecorderSupplement.show(this, { pauseOnNextStatement: true });
        // When paused, show inspector.
        if (contextDebugger.isPaused())
            recorderSupplement_1.RecorderSupplement.showInspector(this);
        contextDebugger.on(debugger_1.Debugger.Events.PausedStateChanged, () => {
            recorderSupplement_1.RecorderSupplement.showInspector(this);
        });
        if (utils_1.debugMode() === 'console')
            await this.extendInjectedScript('main', consoleApiSource.source);
    }
    async _ensureVideosPath() {
        if (this._options.recordVideo)
            await utils_1.mkdirIfNeeded(path_1.default.join(this._options.recordVideo.dir, 'dummy'));
    }
    _browserClosed() {
        for (const page of this.pages())
            page._didClose();
        this._didCloseInternal();
    }
    _didCloseInternal() {
        if (this._closedStatus === 'closed') {
            // We can come here twice if we close browser context and browser
            // at the same time.
            return;
        }
        this._closedStatus = 'closed';
        this._deleteAllDownloads();
        this._downloads.clear();
        this._closePromiseFulfill(new Error('Context closed'));
        this.emit(BrowserContext.Events.Close);
    }
    async cookies(urls = []) {
        if (urls && !Array.isArray(urls))
            urls = [urls];
        return await this._doCookies(urls);
    }
    setHTTPCredentials(httpCredentials) {
        return this._doSetHTTPCredentials(httpCredentials);
    }
    async exposeBinding(name, needsHandle, playwrightBinding, world) {
        const identifier = page_1.PageBinding.identifier(name, world);
        if (this._pageBindings.has(identifier))
            throw new Error(`Function "${name}" has been already registered`);
        for (const page of this.pages()) {
            if (page.getBinding(name, world))
                throw new Error(`Function "${name}" has been already registered in one of the pages`);
        }
        const binding = new page_1.PageBinding(name, playwrightBinding, needsHandle, world);
        this._pageBindings.set(identifier, binding);
        await this._doExposeBinding(binding);
    }
    async grantPermissions(permissions, origin) {
        let resolvedOrigin = '*';
        if (origin) {
            const url = new URL(origin);
            resolvedOrigin = url.origin;
        }
        const existing = new Set(this._permissions.get(resolvedOrigin) || []);
        permissions.forEach(p => existing.add(p));
        const list = [...existing.values()];
        this._permissions.set(resolvedOrigin, list);
        await this._doGrantPermissions(resolvedOrigin, list);
    }
    async clearPermissions() {
        this._permissions.clear();
        await this._doClearPermissions();
    }
    setDefaultNavigationTimeout(timeout) {
        this._timeoutSettings.setDefaultNavigationTimeout(timeout);
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
    }
    async _loadDefaultContextAsIs(progress) {
        if (!this.pages().length) {
            const waitForEvent = helper_1.helper.waitForEvent(progress, this, BrowserContext.Events.Page);
            progress.cleanupWhenAborted(() => waitForEvent.dispose);
            const page = (await waitForEvent.promise);
            if (page._pageIsError)
                throw page._pageIsError;
        }
        const pages = this.pages();
        if (pages[0]._pageIsError)
            throw pages[0]._pageIsError;
        await pages[0].mainFrame()._waitForLoadState(progress, 'load');
        return pages;
    }
    async _loadDefaultContext(progress) {
        const pages = await this._loadDefaultContextAsIs(progress);
        if (this._options.isMobile || this._options.locale) {
            // Workaround for:
            // - chromium fails to change isMobile for existing page;
            // - webkit fails to change locale for existing page.
            const oldPage = pages[0];
            await this.newPage(progress.metadata);
            await oldPage.close(progress.metadata);
        }
    }
    _authenticateProxyViaHeader() {
        const proxy = this._options.proxy || this._browser.options.proxy || { username: undefined, password: undefined };
        const { username, password } = proxy;
        if (username) {
            this._options.httpCredentials = { username, password: password };
            const token = Buffer.from(`${username}:${password}`).toString('base64');
            this._options.extraHTTPHeaders = network.mergeHeaders([
                this._options.extraHTTPHeaders,
                network.singleHeader('Proxy-Authorization', `Basic ${token}`),
            ]);
        }
    }
    _authenticateProxyViaCredentials() {
        const proxy = this._options.proxy || this._browser.options.proxy;
        if (!proxy)
            return;
        const { username, password } = proxy;
        if (username)
            this._options.httpCredentials = { username, password: password || '' };
    }
    async _setRequestInterceptor(handler) {
        this._requestInterceptor = handler;
        await this._doUpdateRequestInterception();
    }
    isClosingOrClosed() {
        return this._closedStatus !== 'open';
    }
    async _deleteAllDownloads() {
        await Promise.all(Array.from(this._downloads).map(download => download.artifact.deleteOnContextClose()));
    }
    async close(metadata) {
        var _a;
        if (this._closedStatus === 'open') {
            this.emit(BrowserContext.Events.BeforeClose);
            this._closedStatus = 'closing';
            await ((_a = this._harTracer) === null || _a === void 0 ? void 0 : _a.flush());
            await this.tracing.dispose();
            // Cleanup.
            const promises = [];
            for (const { context, artifact } of this._browser._idToVideo.values()) {
                // Wait for the videos to finish.
                if (context === this)
                    promises.push(artifact.finishedPromise());
            }
            if (this._isPersistentContext) {
                // Close all the pages instead of the context,
                // because we cannot close the default context.
                await Promise.all(this.pages().map(page => page.close(metadata)));
                await this._onClosePersistent();
            }
            else {
                // Close the context.
                await this._doClose();
            }
            // We delete downloads after context closure
            // so that browser does not write to the download file anymore.
            promises.push(this._deleteAllDownloads());
            await Promise.all(promises);
            // Persistent context should also close the browser.
            if (this._isPersistentContext)
                await this._browser.close();
            // Bookkeeping.
            this._didCloseInternal();
        }
        await this._closePromise;
    }
    async newPage(metadata) {
        const pageDelegate = await this.newPageDelegate();
        const pageOrError = await pageDelegate.pageOrError();
        if (pageOrError instanceof page_1.Page) {
            if (pageOrError.isClosed())
                throw new Error('Page has been closed.');
            return pageOrError;
        }
        throw pageOrError;
    }
    addVisitedOrigin(origin) {
        this._origins.add(origin);
    }
    async storageState(metadata) {
        const result = {
            cookies: (await this.cookies()).filter(c => c.value !== ''),
            origins: []
        };
        if (this._origins.size) {
            const internalMetadata = instrumentation_1.internalCallMetadata();
            const page = await this.newPage(internalMetadata);
            await page._setServerRequestInterceptor(handler => {
                handler.fulfill({ body: '<html></html>' }).catch(() => { });
            });
            for (const origin of this._origins) {
                const originStorage = { origin, localStorage: [] };
                const frame = page.mainFrame();
                await frame.goto(internalMetadata, origin);
                const storage = await frame.evaluateExpression(`({
          localStorage: Object.keys(localStorage).map(name => ({ name, value: localStorage.getItem(name) })),
        })`, false, undefined, 'utility');
                originStorage.localStorage = storage.localStorage;
                if (storage.localStorage.length)
                    result.origins.push(originStorage);
            }
            await page.close(internalMetadata);
        }
        return result;
    }
    async setStorageState(metadata, state) {
        if (state.cookies)
            await this.addCookies(state.cookies);
        if (state.origins && state.origins.length) {
            const internalMetadata = instrumentation_1.internalCallMetadata();
            const page = await this.newPage(internalMetadata);
            await page._setServerRequestInterceptor(handler => {
                handler.fulfill({ body: '<html></html>' }).catch(() => { });
            });
            for (const originState of state.origins) {
                const frame = page.mainFrame();
                await frame.goto(metadata, originState.origin);
                await frame.evaluateExpression(`
          originState => {
            for (const { name, value } of (originState.localStorage || []))
              localStorage.setItem(name, value);
          }`, true, originState, 'utility');
            }
            await page.close(internalMetadata);
        }
    }
    async extendInjectedScript(world, source, arg) {
        const installInFrame = (frame) => frame.extendInjectedScript(world, source, arg).catch(() => { });
        const installInPage = (page) => {
            page.on(page_1.Page.Events.InternalFrameNavigatedToNewDocument, installInFrame);
            return Promise.all(page.frames().map(installInFrame));
        };
        this.on(BrowserContext.Events.Page, installInPage);
        return Promise.all(this.pages().map(installInPage));
    }
}
exports.BrowserContext = BrowserContext;
BrowserContext.Events = {
    Close: 'close',
    Page: 'page',
    Request: 'request',
    Response: 'response',
    RequestFailed: 'requestfailed',
    RequestFinished: 'requestfinished',
    BeforeClose: 'beforeclose',
    VideoStarted: 'videostarted',
};
function assertBrowserContextIsNotOwned(context) {
    for (const page of context.pages()) {
        if (page._ownedContext)
            throw new Error('Please use browser.newContext() for multi-page scripts that share the context.');
    }
}
exports.assertBrowserContextIsNotOwned = assertBrowserContextIsNotOwned;
function validateBrowserContextOptions(options, browserOptions) {
    if (options.noDefaultViewport && options.deviceScaleFactor !== undefined)
        throw new Error(`"deviceScaleFactor" option is not supported with null "viewport"`);
    if (options.noDefaultViewport && options.isMobile !== undefined)
        throw new Error(`"isMobile" option is not supported with null "viewport"`);
    if (!options.viewport && !options.noDefaultViewport)
        options.viewport = { width: 1280, height: 720 };
    if (options.recordVideo) {
        if (!options.recordVideo.size) {
            if (options.noDefaultViewport) {
                options.recordVideo.size = { width: 800, height: 600 };
            }
            else {
                const size = options.viewport;
                const scale = Math.min(1, 800 / Math.max(size.width, size.height));
                options.recordVideo.size = {
                    width: Math.floor(size.width * scale),
                    height: Math.floor(size.height * scale)
                };
            }
        }
        // Make sure both dimensions are odd, this is required for vp8
        options.recordVideo.size.width &= ~1;
        options.recordVideo.size.height &= ~1;
    }
    if (options.proxy) {
        if (!browserOptions.proxy && browserOptions.isChromium && os.platform() === 'win32')
            throw new Error(`Browser needs to be launched with the global proxy. If all contexts override the proxy, global proxy will be never used and can be any string, for example "launch({ proxy: { server: 'http://per-context' } })"`);
        options.proxy = normalizeProxySettings(options.proxy);
    }
    if (utils_1.debugMode() === 'inspector')
        options.bypassCSP = true;
    verifyGeolocation(options.geolocation);
    if (!options._debugName)
        options._debugName = utils_1.createGuid();
}
exports.validateBrowserContextOptions = validateBrowserContextOptions;
function verifyGeolocation(geolocation) {
    if (!geolocation)
        return;
    geolocation.accuracy = geolocation.accuracy || 0;
    const { longitude, latitude, accuracy } = geolocation;
    if (longitude < -180 || longitude > 180)
        throw new Error(`geolocation.longitude: precondition -180 <= LONGITUDE <= 180 failed.`);
    if (latitude < -90 || latitude > 90)
        throw new Error(`geolocation.latitude: precondition -90 <= LATITUDE <= 90 failed.`);
    if (accuracy < 0)
        throw new Error(`geolocation.accuracy: precondition 0 <= ACCURACY failed.`);
}
exports.verifyGeolocation = verifyGeolocation;
function normalizeProxySettings(proxy) {
    let { server, bypass } = proxy;
    let url;
    try {
        // new URL('127.0.0.1:8080') throws
        // new URL('localhost:8080') fails to parse host or protocol
        // In both of these cases, we need to try re-parse URL with `http://` prefix.
        url = new URL(server);
        if (!url.host || !url.protocol)
            url = new URL('http://' + server);
    }
    catch (e) {
        url = new URL('http://' + server);
    }
    if (url.protocol === 'socks4:' && (proxy.username || proxy.password))
        throw new Error(`Socks4 proxy protocol does not support authentication`);
    if (url.protocol === 'socks5:' && (proxy.username || proxy.password))
        throw new Error(`Browser does not support socks5 proxy authentication`);
    server = url.protocol + '//' + url.host;
    if (bypass)
        bypass = bypass.split(',').map(t => t.trim()).join(',');
    return { ...proxy, server, bypass };
}
exports.normalizeProxySettings = normalizeProxySettings;
//# sourceMappingURL=browserContext.js.map