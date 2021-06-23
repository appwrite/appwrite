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
Object.defineProperty(exports, "__esModule", { value: true });
exports.CRBrowserContext = exports.CRBrowser = void 0;
const browser_1 = require("../browser");
const browserContext_1 = require("../browserContext");
const utils_1 = require("../../utils/utils");
const network = __importStar(require("../network"));
const page_1 = require("../page");
const crConnection_1 = require("./crConnection");
const crPage_1 = require("./crPage");
const crProtocolHelper_1 = require("./crProtocolHelper");
const crExecutionContext_1 = require("./crExecutionContext");
class CRBrowser extends browser_1.Browser {
    constructor(connection, options) {
        super(options);
        this._clientRootSessionPromise = null;
        this._contexts = new Map();
        this._crPages = new Map();
        this._backgroundPages = new Map();
        this._serviceWorkers = new Map();
        this._isMac = false;
        this._version = '';
        this._tracingRecording = false;
        this._tracingPath = '';
        this._connection = connection;
        this._session = this._connection.rootSession;
        this._connection.on(crConnection_1.ConnectionEvents.Disconnected, () => this._didClose());
        this._session.on('Target.attachedToTarget', this._onAttachedToTarget.bind(this));
        this._session.on('Target.detachedFromTarget', this._onDetachedFromTarget.bind(this));
        this._session.on('Browser.downloadWillBegin', this._onDownloadWillBegin.bind(this));
        this._session.on('Browser.downloadProgress', this._onDownloadProgress.bind(this));
    }
    static async connect(transport, options, devtools) {
        const connection = new crConnection_1.CRConnection(transport, options.protocolLogger, options.browserLogsCollector);
        const browser = new CRBrowser(connection, options);
        browser._devtools = devtools;
        const session = connection.rootSession;
        if (options.__testHookOnConnectToBrowser)
            await options.__testHookOnConnectToBrowser();
        const version = await session.send('Browser.getVersion');
        browser._isMac = version.userAgent.includes('Macintosh');
        browser._version = version.product.substring(version.product.indexOf('/') + 1);
        if (!options.persistent) {
            await session.send('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: true, flatten: true });
            return browser;
        }
        browser._defaultContext = new CRBrowserContext(browser, undefined, options.persistent);
        await Promise.all([
            session.send('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: true, flatten: true }).then(async () => {
                // Target.setAutoAttach has a bug where it does not wait for new Targets being attached.
                // However making a dummy call afterwards fixes this.
                // This can be removed after https://chromium-review.googlesource.com/c/chromium/src/+/2885888 lands in stable.
                await session.send('Target.getTargetInfo');
            }),
            browser._defaultContext._initialize(),
        ]);
        await browser._waitForAllPagesToBeInitialized();
        return browser;
    }
    async newContext(options) {
        browserContext_1.validateBrowserContextOptions(options, this.options);
        const { browserContextId } = await this._session.send('Target.createBrowserContext', {
            disposeOnDetach: true,
            proxyServer: options.proxy ? options.proxy.server : undefined,
            proxyBypassList: options.proxy ? options.proxy.bypass : undefined,
        });
        const context = new CRBrowserContext(this, browserContextId, options);
        await context._initialize();
        this._contexts.set(browserContextId, context);
        return context;
    }
    contexts() {
        return Array.from(this._contexts.values());
    }
    version() {
        return this._version;
    }
    isClank() {
        return this.options.name === 'clank';
    }
    async _waitForAllPagesToBeInitialized() {
        await Promise.all([...this._crPages.values()].map(page => page.pageOrError()));
    }
    _onAttachedToTarget({ targetInfo, sessionId, waitingForDebugger }) {
        if (targetInfo.type === 'browser')
            return;
        const session = this._connection.session(sessionId);
        utils_1.assert(targetInfo.browserContextId, 'targetInfo: ' + JSON.stringify(targetInfo, null, 2));
        let context = this._contexts.get(targetInfo.browserContextId) || null;
        if (!context) {
            // TODO: auto attach only to pages from our contexts.
            // assert(this._defaultContext);
            context = this._defaultContext;
        }
        if (targetInfo.type === 'other' && targetInfo.url.startsWith('devtools://devtools') && this._devtools) {
            this._devtools.install(session);
            return;
        }
        if (targetInfo.type === 'other' || !context) {
            if (waitingForDebugger) {
                // Ideally, detaching should resume any target, but there is a bug in the backend.
                session._sendMayFail('Runtime.runIfWaitingForDebugger').then(() => {
                    this._session._sendMayFail('Target.detachFromTarget', { sessionId });
                });
            }
            return;
        }
        utils_1.assert(!this._crPages.has(targetInfo.targetId), 'Duplicate target ' + targetInfo.targetId);
        utils_1.assert(!this._backgroundPages.has(targetInfo.targetId), 'Duplicate target ' + targetInfo.targetId);
        utils_1.assert(!this._serviceWorkers.has(targetInfo.targetId), 'Duplicate target ' + targetInfo.targetId);
        if (targetInfo.type === 'background_page') {
            const backgroundPage = new crPage_1.CRPage(session, targetInfo.targetId, context, null, false, true);
            this._backgroundPages.set(targetInfo.targetId, backgroundPage);
            return;
        }
        if (targetInfo.type === 'page') {
            const opener = targetInfo.openerId ? this._crPages.get(targetInfo.openerId) || null : null;
            const crPage = new crPage_1.CRPage(session, targetInfo.targetId, context, opener, true, false);
            this._crPages.set(targetInfo.targetId, crPage);
            return;
        }
        if (targetInfo.type === 'service_worker') {
            const serviceWorker = new CRServiceWorker(context, session, targetInfo.url);
            this._serviceWorkers.set(targetInfo.targetId, serviceWorker);
            context.emit(CRBrowserContext.CREvents.ServiceWorker, serviceWorker);
            return;
        }
        utils_1.assert(false, 'Unknown target type: ' + targetInfo.type);
    }
    _onDetachedFromTarget(payload) {
        const targetId = payload.targetId;
        const crPage = this._crPages.get(targetId);
        if (crPage) {
            this._crPages.delete(targetId);
            crPage.didClose();
            return;
        }
        const backgroundPage = this._backgroundPages.get(targetId);
        if (backgroundPage) {
            this._backgroundPages.delete(targetId);
            backgroundPage.didClose();
            return;
        }
        const serviceWorker = this._serviceWorkers.get(targetId);
        if (serviceWorker) {
            this._serviceWorkers.delete(targetId);
            serviceWorker.emit(page_1.Worker.Events.Close);
            return;
        }
    }
    _findOwningPage(frameId) {
        for (const crPage of this._crPages.values()) {
            const frame = crPage._page._frameManager.frame(frameId);
            if (frame)
                return crPage;
        }
        return null;
    }
    _onDownloadWillBegin(payload) {
        const page = this._findOwningPage(payload.frameId);
        utils_1.assert(page, 'Download started in unknown page: ' + JSON.stringify(payload));
        page.willBeginDownload();
        let originPage = page._initializedPage;
        // If it's a new window download, report it on the opener page.
        if (!originPage && page._opener)
            originPage = page._opener._initializedPage;
        if (!originPage)
            return;
        this._downloadCreated(originPage, payload.guid, payload.url, payload.suggestedFilename);
    }
    _onDownloadProgress(payload) {
        if (payload.state === 'completed')
            this._downloadFinished(payload.guid, '');
        if (payload.state === 'canceled')
            this._downloadFinished(payload.guid, 'canceled');
    }
    async _closePage(crPage) {
        await this._session.send('Target.closeTarget', { targetId: crPage._targetId });
    }
    async newBrowserCDPSession() {
        return await this._connection.createBrowserSession();
    }
    async startTracing(page, options = {}) {
        utils_1.assert(!this._tracingRecording, 'Cannot start recording trace while already recording trace.');
        this._tracingClient = page ? page._delegate._mainFrameSession._client : this._session;
        const defaultCategories = [
            '-*', 'devtools.timeline', 'v8.execute', 'disabled-by-default-devtools.timeline',
            'disabled-by-default-devtools.timeline.frame', 'toplevel',
            'blink.console', 'blink.user_timing', 'latencyInfo', 'disabled-by-default-devtools.timeline.stack',
            'disabled-by-default-v8.cpu_profiler', 'disabled-by-default-v8.cpu_profiler.hires'
        ];
        const { path = null, screenshots = false, categories = defaultCategories, } = options;
        if (screenshots)
            categories.push('disabled-by-default-devtools.screenshot');
        this._tracingPath = path;
        this._tracingRecording = true;
        await this._tracingClient.send('Tracing.start', {
            transferMode: 'ReturnAsStream',
            categories: categories.join(',')
        });
    }
    async stopTracing() {
        utils_1.assert(this._tracingClient, 'Tracing was not started.');
        const [event] = await Promise.all([
            new Promise(f => this._tracingClient.once('Tracing.tracingComplete', f)),
            this._tracingClient.send('Tracing.end')
        ]);
        const result = await crProtocolHelper_1.readProtocolStream(this._tracingClient, event.stream, this._tracingPath);
        this._tracingRecording = false;
        return result;
    }
    isConnected() {
        return !this._connection._closed;
    }
    async _clientRootSession() {
        if (!this._clientRootSessionPromise)
            this._clientRootSessionPromise = this._connection.createBrowserSession();
        return this._clientRootSessionPromise;
    }
}
exports.CRBrowser = CRBrowser;
class CRServiceWorker extends page_1.Worker {
    constructor(browserContext, session, url) {
        super(browserContext, url);
        this._browserContext = browserContext;
        session.once('Runtime.executionContextCreated', event => {
            this._createExecutionContext(new crExecutionContext_1.CRExecutionContext(session, event.context));
        });
        // This might fail if the target is closed before we receive all execution contexts.
        session.send('Runtime.enable', {}).catch(e => { });
        session.send('Runtime.runIfWaitingForDebugger').catch(e => { });
    }
}
class CRBrowserContext extends browserContext_1.BrowserContext {
    constructor(browser, browserContextId, options) {
        super(browser, options, browserContextId);
        this._browser = browser;
        this._evaluateOnNewDocumentSources = [];
        this._authenticateProxyViaCredentials();
    }
    async _initialize() {
        utils_1.assert(!Array.from(this._browser._crPages.values()).some(page => page._browserContext === this));
        const promises = [super._initialize()];
        if (this._browser.options.name !== 'electron' && this._browser.options.name !== 'clank') {
            promises.push(this._browser._session.send('Browser.setDownloadBehavior', {
                behavior: this._options.acceptDownloads ? 'allowAndName' : 'deny',
                browserContextId: this._browserContextId,
                downloadPath: this._browser.options.downloadsPath,
                eventsEnabled: true,
            }));
        }
        if (this._options.permissions)
            promises.push(this.grantPermissions(this._options.permissions));
        await Promise.all(promises);
    }
    pages() {
        const result = [];
        for (const crPage of this._browser._crPages.values()) {
            if (crPage._browserContext === this && crPage._initializedPage)
                result.push(crPage._initializedPage);
        }
        return result;
    }
    async newPageDelegate() {
        browserContext_1.assertBrowserContextIsNotOwned(this);
        const oldKeys = this._browser.isClank() ? new Set(this._browser._crPages.keys()) : undefined;
        let { targetId } = await this._browser._session.send('Target.createTarget', { url: 'about:blank', browserContextId: this._browserContextId });
        if (oldKeys) {
            // Chrome for Android returns tab ids (1, 2, 3, 4, 5) instead of content target ids here, work around it via the
            // heuristic assuming that there is only one page created at a time.
            const newKeys = new Set(this._browser._crPages.keys());
            // Remove old keys.
            for (const key of oldKeys)
                newKeys.delete(key);
            // Remove potential concurrent popups.
            for (const key of newKeys) {
                const page = this._browser._crPages.get(key);
                if (page._opener)
                    newKeys.delete(key);
            }
            utils_1.assert(newKeys.size === 1);
            [targetId] = [...newKeys];
        }
        return this._browser._crPages.get(targetId);
    }
    async _doCookies(urls) {
        const { cookies } = await this._browser._session.send('Storage.getCookies', { browserContextId: this._browserContextId });
        return network.filterCookies(cookies.map(c => {
            const copy = { sameSite: 'None', ...c };
            delete copy.size;
            delete copy.priority;
            delete copy.session;
            delete copy.sameParty;
            delete copy.sourceScheme;
            delete copy.sourcePort;
            return copy;
        }), urls);
    }
    async addCookies(cookies) {
        await this._browser._session.send('Storage.setCookies', { cookies: network.rewriteCookies(cookies), browserContextId: this._browserContextId });
    }
    async clearCookies() {
        await this._browser._session.send('Storage.clearCookies', { browserContextId: this._browserContextId });
    }
    async _doGrantPermissions(origin, permissions) {
        const webPermissionToProtocol = new Map([
            ['geolocation', 'geolocation'],
            ['midi', 'midi'],
            ['notifications', 'notifications'],
            ['camera', 'videoCapture'],
            ['microphone', 'audioCapture'],
            ['background-sync', 'backgroundSync'],
            ['ambient-light-sensor', 'sensors'],
            ['accelerometer', 'sensors'],
            ['gyroscope', 'sensors'],
            ['magnetometer', 'sensors'],
            ['accessibility-events', 'accessibilityEvents'],
            ['clipboard-read', 'clipboardReadWrite'],
            ['clipboard-write', 'clipboardSanitizedWrite'],
            ['payment-handler', 'paymentHandler'],
            // chrome-specific permissions we have.
            ['midi-sysex', 'midiSysex'],
        ]);
        const filtered = permissions.map(permission => {
            const protocolPermission = webPermissionToProtocol.get(permission);
            if (!protocolPermission)
                throw new Error('Unknown permission: ' + permission);
            return protocolPermission;
        });
        await this._browser._session.send('Browser.grantPermissions', { origin: origin === '*' ? undefined : origin, browserContextId: this._browserContextId, permissions: filtered });
    }
    async _doClearPermissions() {
        await this._browser._session.send('Browser.resetPermissions', { browserContextId: this._browserContextId });
    }
    async setGeolocation(geolocation) {
        browserContext_1.verifyGeolocation(geolocation);
        this._options.geolocation = geolocation;
        for (const page of this.pages())
            await page._delegate.updateGeolocation();
    }
    async setExtraHTTPHeaders(headers) {
        this._options.extraHTTPHeaders = headers;
        for (const page of this.pages())
            await page._delegate.updateExtraHTTPHeaders();
    }
    async setOffline(offline) {
        this._options.offline = offline;
        for (const page of this.pages())
            await page._delegate.updateOffline();
    }
    async _doSetHTTPCredentials(httpCredentials) {
        this._options.httpCredentials = httpCredentials;
        for (const page of this.pages())
            await page._delegate.updateHttpCredentials();
    }
    async _doAddInitScript(source) {
        this._evaluateOnNewDocumentSources.push(source);
        for (const page of this.pages())
            await page._delegate.evaluateOnNewDocument(source);
    }
    async _doExposeBinding(binding) {
        for (const page of this.pages())
            await page._delegate.exposeBinding(binding);
    }
    async _doUpdateRequestInterception() {
        for (const page of this.pages())
            await page._delegate.updateRequestInterception();
    }
    async _doClose() {
        utils_1.assert(this._browserContextId);
        await this._browser._session.send('Target.disposeBrowserContext', { browserContextId: this._browserContextId });
        this._browser._contexts.delete(this._browserContextId);
        for (const [targetId, serviceWorker] of this._browser._serviceWorkers) {
            if (serviceWorker._browserContext !== this)
                continue;
            // When closing a browser context, service workers are shutdown
            // asynchronously and we get detached from them later.
            // To avoid the wrong order of notifications, we manually fire
            // "close" event here and forget about the serivce worker.
            serviceWorker.emit(page_1.Worker.Events.Close);
            this._browser._serviceWorkers.delete(targetId);
        }
    }
    async _onClosePersistent() {
        for (const [targetId, backgroundPage] of this._browser._backgroundPages.entries()) {
            if (backgroundPage._browserContext === this && backgroundPage._initializedPage) {
                backgroundPage.didClose();
                this._browser._backgroundPages.delete(targetId);
            }
        }
    }
    backgroundPages() {
        const result = [];
        for (const backgroundPage of this._browser._backgroundPages.values()) {
            if (backgroundPage._browserContext === this && backgroundPage._initializedPage)
                result.push(backgroundPage._initializedPage);
        }
        return result;
    }
    serviceWorkers() {
        return Array.from(this._browser._serviceWorkers.values()).filter(serviceWorker => serviceWorker._browserContext === this);
    }
    async newCDPSession(page) {
        if (!(page instanceof page_1.Page))
            throw new Error('page: expected Page');
        const targetId = page._delegate._targetId;
        const rootSession = await this._browser._clientRootSession();
        const { sessionId } = await rootSession.send('Target.attachToTarget', { targetId, flatten: true });
        return this._browser._connection.session(sessionId);
    }
}
exports.CRBrowserContext = CRBrowserContext;
CRBrowserContext.CREvents = {
    BackgroundPage: 'backgroundpage',
    ServiceWorker: 'serviceworker',
};
//# sourceMappingURL=crBrowser.js.map