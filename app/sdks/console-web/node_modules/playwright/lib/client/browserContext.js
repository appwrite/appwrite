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
exports.prepareBrowserContextParams = exports.BrowserContext = void 0;
const page_1 = require("./page");
const network = __importStar(require("./network"));
const fs_1 = __importDefault(require("fs"));
const channelOwner_1 = require("./channelOwner");
const clientHelper_1 = require("./clientHelper");
const browser_1 = require("./browser");
const worker_1 = require("./worker");
const events_1 = require("./events");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const waiter_1 = require("./waiter");
const utils_1 = require("../utils/utils");
const errors_1 = require("../utils/errors");
const cdpSession_1 = require("./cdpSession");
const tracing_1 = require("./tracing");
class BrowserContext extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        var _a;
        super(parent, type, guid, initializer);
        this._pages = new Set();
        this._routes = [];
        this._browser = null;
        this._bindings = new Map();
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
        this._options = {
            sdkLanguage: 'javascript'
        };
        this._backgroundPages = new Set();
        this._serviceWorkers = new Set();
        if (parent instanceof browser_1.Browser)
            this._browser = parent;
        this._isChromium = ((_a = this._browser) === null || _a === void 0 ? void 0 : _a._name) === 'chromium';
        this.tracing = new tracing_1.Tracing(this);
        this._channel.on('bindingCall', ({ binding }) => this._onBinding(page_1.BindingCall.from(binding)));
        this._channel.on('close', () => this._onClose());
        this._channel.on('page', ({ page }) => this._onPage(page_1.Page.from(page)));
        this._channel.on('route', ({ route, request }) => this._onRoute(network.Route.from(route), network.Request.from(request)));
        this._channel.on('backgroundPage', ({ page }) => {
            const backgroundPage = page_1.Page.from(page);
            this._backgroundPages.add(backgroundPage);
            this.emit(events_1.Events.BrowserContext.BackgroundPage, backgroundPage);
        });
        this._channel.on('serviceWorker', ({ worker }) => {
            const serviceWorker = worker_1.Worker.from(worker);
            serviceWorker._context = this;
            this._serviceWorkers.add(serviceWorker);
            this.emit(events_1.Events.BrowserContext.ServiceWorker, serviceWorker);
        });
        this._channel.on('request', ({ request, page }) => this._onRequest(network.Request.from(request), page_1.Page.fromNullable(page)));
        this._channel.on('requestFailed', ({ request, failureText, responseEndTiming, page }) => this._onRequestFailed(network.Request.from(request), responseEndTiming, failureText, page_1.Page.fromNullable(page)));
        this._channel.on('requestFinished', ({ request, responseEndTiming, page }) => this._onRequestFinished(network.Request.from(request), responseEndTiming, page_1.Page.fromNullable(page)));
        this._channel.on('response', ({ response, page }) => this._onResponse(network.Response.from(response), page_1.Page.fromNullable(page)));
        this._closedPromise = new Promise(f => this.once(events_1.Events.BrowserContext.Close, f));
    }
    static from(context) {
        return context._object;
    }
    static fromNullable(context) {
        return context ? BrowserContext.from(context) : null;
    }
    _onPage(page) {
        this._pages.add(page);
        this.emit(events_1.Events.BrowserContext.Page, page);
        if (page._opener && !page._opener.isClosed())
            page._opener.emit(events_1.Events.Page.Popup, page);
    }
    _onRequest(request, page) {
        this.emit(events_1.Events.BrowserContext.Request, request);
        if (page)
            page.emit(events_1.Events.Page.Request, request);
    }
    _onResponse(response, page) {
        this.emit(events_1.Events.BrowserContext.Response, response);
        if (page)
            page.emit(events_1.Events.Page.Response, response);
    }
    _onRequestFailed(request, responseEndTiming, failureText, page) {
        request._failureText = failureText || null;
        if (request._timing)
            request._timing.responseEnd = responseEndTiming;
        this.emit(events_1.Events.BrowserContext.RequestFailed, request);
        if (page)
            page.emit(events_1.Events.Page.RequestFailed, request);
    }
    _onRequestFinished(request, responseEndTiming, page) {
        if (request._timing)
            request._timing.responseEnd = responseEndTiming;
        this.emit(events_1.Events.BrowserContext.RequestFinished, request);
        if (page)
            page.emit(events_1.Events.Page.RequestFinished, request);
    }
    _onRoute(route, request) {
        for (const { url, handler } of this._routes) {
            if (clientHelper_1.urlMatches(request.url(), url)) {
                handler(route, request);
                return;
            }
        }
        // it can race with BrowserContext.close() which then throws since its closed
        route.continue().catch(() => { });
    }
    async _onBinding(bindingCall) {
        const func = this._bindings.get(bindingCall._initializer.name);
        if (!func)
            return;
        await bindingCall.call(func);
    }
    setDefaultNavigationTimeout(timeout) {
        this._timeoutSettings.setDefaultNavigationTimeout(timeout);
        this._channel.setDefaultNavigationTimeoutNoReply({ timeout });
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
        this._channel.setDefaultTimeoutNoReply({ timeout });
    }
    browser() {
        return this._browser;
    }
    pages() {
        return [...this._pages];
    }
    async newPage() {
        return this._wrapApiCall('browserContext.newPage', async (channel) => {
            if (this._ownerPage)
                throw new Error('Please use browser.newContext()');
            return page_1.Page.from((await channel.newPage()).page);
        });
    }
    async cookies(urls) {
        if (!urls)
            urls = [];
        if (urls && typeof urls === 'string')
            urls = [urls];
        return this._wrapApiCall('browserContext.cookies', async (channel) => {
            return (await channel.cookies({ urls: urls })).cookies;
        });
    }
    async addCookies(cookies) {
        return this._wrapApiCall('browserContext.addCookies', async (channel) => {
            await channel.addCookies({ cookies });
        });
    }
    async clearCookies() {
        return this._wrapApiCall('browserContext.clearCookies', async (channel) => {
            await channel.clearCookies();
        });
    }
    async grantPermissions(permissions, options) {
        return this._wrapApiCall('browserContext.grantPermissions', async (channel) => {
            await channel.grantPermissions({ permissions, ...options });
        });
    }
    async clearPermissions() {
        return this._wrapApiCall('browserContext.clearPermissions', async (channel) => {
            await channel.clearPermissions();
        });
    }
    async setGeolocation(geolocation) {
        return this._wrapApiCall('browserContext.setGeolocation', async (channel) => {
            await channel.setGeolocation({ geolocation: geolocation || undefined });
        });
    }
    async setExtraHTTPHeaders(headers) {
        return this._wrapApiCall('browserContext.setExtraHTTPHeaders', async (channel) => {
            network.validateHeaders(headers);
            await channel.setExtraHTTPHeaders({ headers: utils_1.headersObjectToArray(headers) });
        });
    }
    async setOffline(offline) {
        return this._wrapApiCall('browserContext.setOffline', async (channel) => {
            await channel.setOffline({ offline });
        });
    }
    async setHTTPCredentials(httpCredentials) {
        if (!utils_1.isUnderTest())
            clientHelper_1.deprecate(`context.setHTTPCredentials`, `warning: method |context.setHTTPCredentials()| is deprecated. Instead of changing credentials, create another browser context with new credentials.`);
        return this._wrapApiCall('browserContext.setHTTPCredentials', async (channel) => {
            await channel.setHTTPCredentials({ httpCredentials: httpCredentials || undefined });
        });
    }
    async addInitScript(script, arg) {
        return this._wrapApiCall('browserContext.addInitScript', async (channel) => {
            const source = await clientHelper_1.evaluationScript(script, arg);
            await channel.addInitScript({ source });
        });
    }
    async exposeBinding(name, callback, options = {}) {
        return this._wrapApiCall('browserContext.exposeBinding', async (channel) => {
            await channel.exposeBinding({ name, needsHandle: options.handle });
            this._bindings.set(name, callback);
        });
    }
    async exposeFunction(name, callback) {
        return this._wrapApiCall('browserContext.exposeFunction', async (channel) => {
            await channel.exposeBinding({ name });
            const binding = (source, ...args) => callback(...args);
            this._bindings.set(name, binding);
        });
    }
    async route(url, handler) {
        return this._wrapApiCall('browserContext.route', async (channel) => {
            this._routes.push({ url, handler });
            if (this._routes.length === 1)
                await channel.setNetworkInterceptionEnabled({ enabled: true });
        });
    }
    async unroute(url, handler) {
        return this._wrapApiCall('browserContext.unroute', async (channel) => {
            this._routes = this._routes.filter(route => route.url !== url || (handler && route.handler !== handler));
            if (this._routes.length === 0)
                await channel.setNetworkInterceptionEnabled({ enabled: false });
        });
    }
    async waitForEvent(event, optionsOrPredicate = {}) {
        const timeout = this._timeoutSettings.timeout(typeof optionsOrPredicate === 'function' ? {} : optionsOrPredicate);
        const predicate = typeof optionsOrPredicate === 'function' ? optionsOrPredicate : optionsOrPredicate.predicate;
        const waiter = waiter_1.Waiter.createForEvent(this, 'browserContext', event);
        waiter.rejectOnTimeout(timeout, `Timeout while waiting for event "${event}"`);
        if (event !== events_1.Events.BrowserContext.Close)
            waiter.rejectOnEvent(this, events_1.Events.BrowserContext.Close, new Error('Context closed'));
        const result = await waiter.waitForEvent(this, event, predicate);
        waiter.dispose();
        return result;
    }
    async storageState(options = {}) {
        return await this._wrapApiCall('browserContext.storageState', async (channel) => {
            const state = await channel.storageState();
            if (options.path) {
                await utils_1.mkdirIfNeeded(options.path);
                await fs_1.default.promises.writeFile(options.path, JSON.stringify(state, undefined, 2), 'utf8');
            }
            return state;
        });
    }
    backgroundPages() {
        return [...this._backgroundPages];
    }
    serviceWorkers() {
        return [...this._serviceWorkers];
    }
    async newCDPSession(page) {
        return this._wrapApiCall('browserContext.newCDPSession', async (channel) => {
            const result = await channel.newCDPSession({ page: page._channel });
            return cdpSession_1.CDPSession.from(result.session);
        });
    }
    _onClose() {
        if (this._browser)
            this._browser._contexts.delete(this);
        this.emit(events_1.Events.BrowserContext.Close, this);
    }
    async close() {
        try {
            await this._wrapApiCall('browserContext.close', async (channel) => {
                await channel.close();
                await this._closedPromise;
            });
        }
        catch (e) {
            if (errors_1.isSafeCloseError(e))
                return;
            throw e;
        }
    }
    async _enableRecorder(params) {
        await this._channel.recorderSupplementEnable(params);
    }
}
exports.BrowserContext = BrowserContext;
async function prepareBrowserContextParams(options) {
    if (options.videoSize && !options.videosPath)
        throw new Error(`"videoSize" option requires "videosPath" to be specified`);
    if (options.extraHTTPHeaders)
        network.validateHeaders(options.extraHTTPHeaders);
    const contextParams = {
        sdkLanguage: 'javascript',
        ...options,
        viewport: options.viewport === null ? undefined : options.viewport,
        noDefaultViewport: options.viewport === null,
        extraHTTPHeaders: options.extraHTTPHeaders ? utils_1.headersObjectToArray(options.extraHTTPHeaders) : undefined,
        storageState: typeof options.storageState === 'string' ? JSON.parse(await fs_1.default.promises.readFile(options.storageState, 'utf8')) : options.storageState,
    };
    if (!contextParams.recordVideo && options.videosPath) {
        contextParams.recordVideo = {
            dir: options.videosPath,
            size: options.videoSize
        };
    }
    return contextParams;
}
exports.prepareBrowserContextParams = prepareBrowserContextParams;
//# sourceMappingURL=browserContext.js.map