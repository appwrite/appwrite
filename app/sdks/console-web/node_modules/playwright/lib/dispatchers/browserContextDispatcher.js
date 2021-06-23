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
exports.BrowserContextDispatcher = void 0;
const browserContext_1 = require("../server/browserContext");
const dispatcher_1 = require("./dispatcher");
const pageDispatcher_1 = require("./pageDispatcher");
const networkDispatchers_1 = require("./networkDispatchers");
const crBrowser_1 = require("../server/chromium/crBrowser");
const cdpSessionDispatcher_1 = require("./cdpSessionDispatcher");
const recorderSupplement_1 = require("../server/supplements/recorderSupplement");
const artifactDispatcher_1 = require("./artifactDispatcher");
class BrowserContextDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, context) {
        super(scope, context, 'BrowserContext', { isChromium: context._browser.options.isChromium }, true);
        this._context = context;
        // Note: when launching persistent context, dispatcher is created very late,
        // so we can already have pages, videos and everything else.
        const onVideo = (artifact) => {
            // Note: Video must outlive Page and BrowserContext, so that client can saveAs it
            // after closing the context. We use |scope| for it.
            const artifactDispatcher = new artifactDispatcher_1.ArtifactDispatcher(scope, artifact);
            this._dispatchEvent('video', { artifact: artifactDispatcher });
        };
        context.on(browserContext_1.BrowserContext.Events.VideoStarted, onVideo);
        for (const video of context._browser._idToVideo.values()) {
            if (video.context === context)
                onVideo(video.artifact);
        }
        for (const page of context.pages())
            this._dispatchEvent('page', { page: new pageDispatcher_1.PageDispatcher(this._scope, page) });
        context.on(browserContext_1.BrowserContext.Events.Page, page => this._dispatchEvent('page', { page: new pageDispatcher_1.PageDispatcher(this._scope, page) }));
        context.on(browserContext_1.BrowserContext.Events.Close, () => {
            this._dispatchEvent('close');
            this._dispose();
        });
        if (context._browser.options.name === 'chromium') {
            for (const page of context.backgroundPages())
                this._dispatchEvent('backgroundPage', { page: new pageDispatcher_1.PageDispatcher(this._scope, page) });
            context.on(crBrowser_1.CRBrowserContext.CREvents.BackgroundPage, page => this._dispatchEvent('backgroundPage', { page: new pageDispatcher_1.PageDispatcher(this._scope, page) }));
            for (const serviceWorker of context.serviceWorkers())
                this._dispatchEvent('serviceWorker', { worker: new pageDispatcher_1.WorkerDispatcher(this._scope, serviceWorker) });
            context.on(crBrowser_1.CRBrowserContext.CREvents.ServiceWorker, serviceWorker => this._dispatchEvent('serviceWorker', { worker: new pageDispatcher_1.WorkerDispatcher(this._scope, serviceWorker) }));
        }
        context.on(browserContext_1.BrowserContext.Events.Request, (request) => {
            return this._dispatchEvent('request', {
                request: networkDispatchers_1.RequestDispatcher.from(this._scope, request),
                page: pageDispatcher_1.PageDispatcher.fromNullable(this._scope, request.frame()._page.initializedOrUndefined())
            });
        });
        context.on(browserContext_1.BrowserContext.Events.Response, (response) => this._dispatchEvent('response', {
            response: networkDispatchers_1.ResponseDispatcher.from(this._scope, response),
            page: pageDispatcher_1.PageDispatcher.fromNullable(this._scope, response.frame()._page.initializedOrUndefined())
        }));
        context.on(browserContext_1.BrowserContext.Events.RequestFailed, (request) => this._dispatchEvent('requestFailed', {
            request: networkDispatchers_1.RequestDispatcher.from(this._scope, request),
            failureText: request._failureText,
            responseEndTiming: request._responseEndTiming,
            page: pageDispatcher_1.PageDispatcher.fromNullable(this._scope, request.frame()._page.initializedOrUndefined())
        }));
        context.on(browserContext_1.BrowserContext.Events.RequestFinished, (request) => this._dispatchEvent('requestFinished', {
            request: networkDispatchers_1.RequestDispatcher.from(scope, request),
            responseEndTiming: request._responseEndTiming,
            page: pageDispatcher_1.PageDispatcher.fromNullable(this._scope, request.frame()._page.initializedOrUndefined())
        }));
    }
    async setDefaultNavigationTimeoutNoReply(params) {
        this._context.setDefaultNavigationTimeout(params.timeout);
    }
    async setDefaultTimeoutNoReply(params) {
        this._context.setDefaultTimeout(params.timeout);
    }
    async exposeBinding(params) {
        await this._context.exposeBinding(params.name, !!params.needsHandle, (source, ...args) => {
            const binding = new pageDispatcher_1.BindingCallDispatcher(this._scope, params.name, !!params.needsHandle, source, args);
            this._dispatchEvent('bindingCall', { binding });
            return binding.promise();
        }, 'main');
    }
    async newPage(params, metadata) {
        return { page: dispatcher_1.lookupDispatcher(await this._context.newPage(metadata)) };
    }
    async cookies(params) {
        return { cookies: await this._context.cookies(params.urls) };
    }
    async addCookies(params) {
        await this._context.addCookies(params.cookies);
    }
    async clearCookies() {
        await this._context.clearCookies();
    }
    async grantPermissions(params) {
        await this._context.grantPermissions(params.permissions, params.origin);
    }
    async clearPermissions() {
        await this._context.clearPermissions();
    }
    async setGeolocation(params) {
        await this._context.setGeolocation(params.geolocation);
    }
    async setExtraHTTPHeaders(params) {
        await this._context.setExtraHTTPHeaders(params.headers);
    }
    async setOffline(params) {
        await this._context.setOffline(params.offline);
    }
    async setHTTPCredentials(params) {
        await this._context.setHTTPCredentials(params.httpCredentials);
    }
    async addInitScript(params) {
        await this._context._doAddInitScript(params.source);
    }
    async setNetworkInterceptionEnabled(params) {
        if (!params.enabled) {
            await this._context._setRequestInterceptor(undefined);
            return;
        }
        await this._context._setRequestInterceptor((route, request) => {
            this._dispatchEvent('route', { route: networkDispatchers_1.RouteDispatcher.from(this._scope, route), request: networkDispatchers_1.RequestDispatcher.from(this._scope, request) });
        });
    }
    async storageState(params, metadata) {
        return await this._context.storageState(metadata);
    }
    async close(params, metadata) {
        await this._context.close(metadata);
    }
    async recorderSupplementEnable(params) {
        await recorderSupplement_1.RecorderSupplement.show(this._context, params);
    }
    async pause(params, metadata) {
        // Inspector controller will take care of this.
    }
    async newCDPSession(params) {
        if (!this._object._browser.options.isChromium)
            throw new Error(`CDP session is only available in Chromium`);
        const crBrowserContext = this._object;
        return { session: new cdpSessionDispatcher_1.CDPSessionDispatcher(this._scope, await crBrowserContext.newCDPSession(params.page._object)) };
    }
    async tracingStart(params) {
        await this._context.tracing.start(params);
    }
    async tracingStop(params) {
        await this._context.tracing.stop();
    }
    async tracingExport(params) {
        const artifact = await this._context.tracing.export();
        return { artifact: new artifactDispatcher_1.ArtifactDispatcher(this._scope, artifact) };
    }
}
exports.BrowserContextDispatcher = BrowserContextDispatcher;
//# sourceMappingURL=browserContextDispatcher.js.map