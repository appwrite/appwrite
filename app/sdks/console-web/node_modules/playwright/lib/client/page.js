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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.BindingCall = exports.Page = void 0;
const events_1 = require("./events");
const utils_1 = require("../utils/utils");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const serializers_1 = require("../protocol/serializers");
const accessibility_1 = require("./accessibility");
const channelOwner_1 = require("./channelOwner");
const consoleMessage_1 = require("./consoleMessage");
const dialog_1 = require("./dialog");
const download_1 = require("./download");
const elementHandle_1 = require("./elementHandle");
const worker_1 = require("./worker");
const frame_1 = require("./frame");
const input_1 = require("./input");
const jsHandle_1 = require("./jsHandle");
const network_1 = require("./network");
const fileChooser_1 = require("./fileChooser");
const buffer_1 = require("buffer");
const coverage_1 = require("./coverage");
const waiter_1 = require("./waiter");
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const clientHelper_1 = require("./clientHelper");
const utils_2 = require("../utils/utils");
const errors_1 = require("../utils/errors");
const video_1 = require("./video");
const artifact_1 = require("./artifact");
class Page extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._frames = new Set();
        this._workers = new Set();
        this._closed = false;
        this._routes = [];
        this._bindings = new Map();
        this._isPageCall = false;
        this._video = null;
        this._browserContext = parent;
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings(this._browserContext._timeoutSettings);
        this.accessibility = new accessibility_1.Accessibility(this._channel);
        this.keyboard = new input_1.Keyboard(this._channel);
        this.mouse = new input_1.Mouse(this._channel);
        this.touchscreen = new input_1.Touchscreen(this._channel);
        this._mainFrame = frame_1.Frame.from(initializer.mainFrame);
        this._mainFrame._page = this;
        this._frames.add(this._mainFrame);
        this._viewportSize = initializer.viewportSize || null;
        this._closed = initializer.isClosed;
        this._opener = Page.fromNullable(initializer.opener);
        this._channel.on('bindingCall', ({ binding }) => this._onBinding(BindingCall.from(binding)));
        this._channel.on('close', () => this._onClose());
        this._channel.on('console', ({ message }) => this.emit(events_1.Events.Page.Console, consoleMessage_1.ConsoleMessage.from(message)));
        this._channel.on('crash', () => this._onCrash());
        this._channel.on('dialog', ({ dialog }) => {
            if (!this.emit(events_1.Events.Page.Dialog, dialog_1.Dialog.from(dialog)))
                dialog.dismiss().catch(() => { });
        });
        this._channel.on('domcontentloaded', () => this.emit(events_1.Events.Page.DOMContentLoaded, this));
        this._channel.on('download', ({ url, suggestedFilename, artifact }) => {
            const artifactObject = artifact_1.Artifact.from(artifact);
            artifactObject._isRemote = !!this._browserContext._browser && !!this._browserContext._browser._remoteType;
            this.emit(events_1.Events.Page.Download, new download_1.Download(this, url, suggestedFilename, artifactObject));
        });
        this._channel.on('fileChooser', ({ element, isMultiple }) => this.emit(events_1.Events.Page.FileChooser, new fileChooser_1.FileChooser(this, elementHandle_1.ElementHandle.from(element), isMultiple)));
        this._channel.on('frameAttached', ({ frame }) => this._onFrameAttached(frame_1.Frame.from(frame)));
        this._channel.on('frameDetached', ({ frame }) => this._onFrameDetached(frame_1.Frame.from(frame)));
        this._channel.on('load', () => this.emit(events_1.Events.Page.Load, this));
        this._channel.on('pageError', ({ error }) => this.emit(events_1.Events.Page.PageError, serializers_1.parseError(error)));
        this._channel.on('route', ({ route, request }) => this._onRoute(network_1.Route.from(route), network_1.Request.from(request)));
        this._channel.on('video', ({ artifact }) => {
            const artifactObject = artifact_1.Artifact.from(artifact);
            this._forceVideo()._artifactReady(artifactObject);
        });
        this._channel.on('webSocket', ({ webSocket }) => this.emit(events_1.Events.Page.WebSocket, network_1.WebSocket.from(webSocket)));
        this._channel.on('worker', ({ worker }) => this._onWorker(worker_1.Worker.from(worker)));
        this.coverage = new coverage_1.Coverage(this._channel);
        this._closedOrCrashedPromise = Promise.race([
            new Promise(f => this.once(events_1.Events.Page.Close, f)),
            new Promise(f => this.once(events_1.Events.Page.Crash, f)),
        ]);
    }
    static from(page) {
        return page._object;
    }
    static fromNullable(page) {
        return page ? Page.from(page) : null;
    }
    _onFrameAttached(frame) {
        frame._page = this;
        this._frames.add(frame);
        if (frame._parentFrame)
            frame._parentFrame._childFrames.add(frame);
        this.emit(events_1.Events.Page.FrameAttached, frame);
    }
    _onFrameDetached(frame) {
        this._frames.delete(frame);
        frame._detached = true;
        if (frame._parentFrame)
            frame._parentFrame._childFrames.delete(frame);
        this.emit(events_1.Events.Page.FrameDetached, frame);
    }
    _onRoute(route, request) {
        for (const { url, handler } of this._routes) {
            if (clientHelper_1.urlMatches(request.url(), url)) {
                handler(route, request);
                return;
            }
        }
        this._browserContext._onRoute(route, request);
    }
    async _onBinding(bindingCall) {
        const func = this._bindings.get(bindingCall._initializer.name);
        if (func) {
            await bindingCall.call(func);
            return;
        }
        await this._browserContext._onBinding(bindingCall);
    }
    _onWorker(worker) {
        this._workers.add(worker);
        worker._page = this;
        this.emit(events_1.Events.Page.Worker, worker);
    }
    _onClose() {
        this._closed = true;
        this._browserContext._pages.delete(this);
        this._browserContext._backgroundPages.delete(this);
        this.emit(events_1.Events.Page.Close, this);
    }
    _onCrash() {
        this.emit(events_1.Events.Page.Crash, this);
    }
    context() {
        return this._browserContext;
    }
    async opener() {
        if (!this._opener || this._opener.isClosed())
            return null;
        return this._opener;
    }
    mainFrame() {
        return this._mainFrame;
    }
    frame(frameSelector) {
        const name = utils_2.isString(frameSelector) ? frameSelector : frameSelector.name;
        const url = utils_2.isObject(frameSelector) ? frameSelector.url : undefined;
        utils_1.assert(name || url, 'Either name or url matcher should be specified');
        return this.frames().find(f => {
            if (name)
                return f.name() === name;
            return clientHelper_1.urlMatches(f.url(), url);
        }) || null;
    }
    frames() {
        return [...this._frames];
    }
    setDefaultNavigationTimeout(timeout) {
        this._timeoutSettings.setDefaultNavigationTimeout(timeout);
        this._channel.setDefaultNavigationTimeoutNoReply({ timeout });
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
        this._channel.setDefaultTimeoutNoReply({ timeout });
    }
    _forceVideo() {
        if (!this._video)
            this._video = new video_1.Video(this);
        return this._video;
    }
    video() {
        // Note: we are creating Video object lazily, because we do not know
        // BrowserContextOptions when constructing the page - it is assigned
        // too late during launchPersistentContext.
        if (!this._browserContext._options.recordVideo)
            return null;
        return this._forceVideo();
    }
    _attributeToPage(func) {
        try {
            this._isPageCall = true;
            return func();
        }
        finally {
            this._isPageCall = false;
        }
    }
    async $(selector) {
        return this._attributeToPage(() => this._mainFrame.$(selector));
    }
    async waitForSelector(selector, options) {
        return this._attributeToPage(() => this._mainFrame.waitForSelector(selector, options));
    }
    async dispatchEvent(selector, type, eventInit, options) {
        return this._attributeToPage(() => this._mainFrame.dispatchEvent(selector, type, eventInit, options));
    }
    async evaluateHandle(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._attributeToPage(() => this._mainFrame.evaluateHandle(pageFunction, arg));
    }
    async $eval(selector, pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 3);
        return this._attributeToPage(() => this._mainFrame.$eval(selector, pageFunction, arg));
    }
    async $$eval(selector, pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 3);
        return this._attributeToPage(() => this._mainFrame.$$eval(selector, pageFunction, arg));
    }
    async $$(selector) {
        return this._attributeToPage(() => this._mainFrame.$$(selector));
    }
    async addScriptTag(options = {}) {
        return this._attributeToPage(() => this._mainFrame.addScriptTag(options));
    }
    async addStyleTag(options = {}) {
        return this._attributeToPage(() => this._mainFrame.addStyleTag(options));
    }
    async exposeFunction(name, callback) {
        return this._wrapApiCall('page.exposeFunction', async (channel) => {
            await channel.exposeBinding({ name });
            const binding = (source, ...args) => callback(...args);
            this._bindings.set(name, binding);
        });
    }
    async exposeBinding(name, callback, options = {}) {
        return this._wrapApiCall('page.exposeBinding', async (channel) => {
            await channel.exposeBinding({ name, needsHandle: options.handle });
            this._bindings.set(name, callback);
        });
    }
    async setExtraHTTPHeaders(headers) {
        return this._wrapApiCall('page.setExtraHTTPHeaders', async (channel) => {
            network_1.validateHeaders(headers);
            await channel.setExtraHTTPHeaders({ headers: utils_2.headersObjectToArray(headers) });
        });
    }
    url() {
        return this._attributeToPage(() => this._mainFrame.url());
    }
    async content() {
        return this._attributeToPage(() => this._mainFrame.content());
    }
    async setContent(html, options) {
        return this._attributeToPage(() => this._mainFrame.setContent(html, options));
    }
    async goto(url, options) {
        return this._attributeToPage(() => this._mainFrame.goto(url, options));
    }
    async reload(options = {}) {
        return this._wrapApiCall('page.reload', async (channel) => {
            const waitUntil = frame_1.verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            return network_1.Response.fromNullable((await channel.reload({ ...options, waitUntil })).response);
        });
    }
    async waitForLoadState(state, options) {
        return this._attributeToPage(() => this._mainFrame.waitForLoadState(state, options));
    }
    async waitForNavigation(options) {
        return this._attributeToPage(() => this._mainFrame.waitForNavigation(options));
    }
    async waitForURL(url, options) {
        return this._attributeToPage(() => this._mainFrame.waitForURL(url, options));
    }
    async waitForRequest(urlOrPredicate, options = {}) {
        return this._wrapApiCall('page.waitForRequest', async (channel) => {
            const predicate = (request) => {
                if (utils_2.isString(urlOrPredicate) || utils_2.isRegExp(urlOrPredicate))
                    return clientHelper_1.urlMatches(request.url(), urlOrPredicate);
                return urlOrPredicate(request);
            };
            const trimmedUrl = trimUrl(urlOrPredicate);
            const logLine = trimmedUrl ? `waiting for request ${trimmedUrl}` : undefined;
            return this._waitForEvent(events_1.Events.Page.Request, { predicate, timeout: options.timeout }, logLine);
        });
    }
    async waitForResponse(urlOrPredicate, options = {}) {
        return this._wrapApiCall('page.waitForResponse', async (channel) => {
            const predicate = (response) => {
                if (utils_2.isString(urlOrPredicate) || utils_2.isRegExp(urlOrPredicate))
                    return clientHelper_1.urlMatches(response.url(), urlOrPredicate);
                return urlOrPredicate(response);
            };
            const trimmedUrl = trimUrl(urlOrPredicate);
            const logLine = trimmedUrl ? `waiting for response ${trimmedUrl}` : undefined;
            return this._waitForEvent(events_1.Events.Page.Response, { predicate, timeout: options.timeout }, logLine);
        });
    }
    async waitForEvent(event, optionsOrPredicate = {}) {
        return this._wrapApiCall('page.waitForEvent', async (channel) => {
            return this._waitForEvent(event, optionsOrPredicate, `waiting for event "${event}"`);
        });
    }
    async _waitForEvent(event, optionsOrPredicate, logLine) {
        const timeout = this._timeoutSettings.timeout(typeof optionsOrPredicate === 'function' ? {} : optionsOrPredicate);
        const predicate = typeof optionsOrPredicate === 'function' ? optionsOrPredicate : optionsOrPredicate.predicate;
        const waiter = waiter_1.Waiter.createForEvent(this, 'page', event);
        if (logLine)
            waiter.log(logLine);
        waiter.rejectOnTimeout(timeout, `Timeout while waiting for event "${event}"`);
        if (event !== events_1.Events.Page.Crash)
            waiter.rejectOnEvent(this, events_1.Events.Page.Crash, new Error('Page crashed'));
        if (event !== events_1.Events.Page.Close)
            waiter.rejectOnEvent(this, events_1.Events.Page.Close, new Error('Page closed'));
        const result = await waiter.waitForEvent(this, event, predicate);
        waiter.dispose();
        return result;
    }
    async goBack(options = {}) {
        return this._wrapApiCall('page.goBack', async (channel) => {
            const waitUntil = frame_1.verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            return network_1.Response.fromNullable((await channel.goBack({ ...options, waitUntil })).response);
        });
    }
    async goForward(options = {}) {
        return this._wrapApiCall('page.goForward', async (channel) => {
            const waitUntil = frame_1.verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            return network_1.Response.fromNullable((await channel.goForward({ ...options, waitUntil })).response);
        });
    }
    async emulateMedia(options = {}) {
        return this._wrapApiCall('page.emulateMedia', async (channel) => {
            await channel.emulateMedia({
                media: options.media === null ? 'null' : options.media,
                colorScheme: options.colorScheme === null ? 'null' : options.colorScheme,
                reducedMotion: options.reducedMotion === null ? 'null' : options.reducedMotion,
            });
        });
    }
    async setViewportSize(viewportSize) {
        return this._wrapApiCall('page.setViewportSize', async (channel) => {
            this._viewportSize = viewportSize;
            await channel.setViewportSize({ viewportSize });
        });
    }
    viewportSize() {
        return this._viewportSize;
    }
    async evaluate(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._attributeToPage(() => this._mainFrame.evaluate(pageFunction, arg));
    }
    async addInitScript(script, arg) {
        return this._wrapApiCall('page.addInitScript', async (channel) => {
            const source = await clientHelper_1.evaluationScript(script, arg);
            await channel.addInitScript({ source });
        });
    }
    async route(url, handler) {
        return this._wrapApiCall('page.route', async (channel) => {
            this._routes.push({ url, handler });
            if (this._routes.length === 1)
                await channel.setNetworkInterceptionEnabled({ enabled: true });
        });
    }
    async unroute(url, handler) {
        return this._wrapApiCall('page.unroute', async (channel) => {
            this._routes = this._routes.filter(route => route.url !== url || (handler && route.handler !== handler));
            if (this._routes.length === 0)
                await channel.setNetworkInterceptionEnabled({ enabled: false });
        });
    }
    async screenshot(options = {}) {
        return this._wrapApiCall('page.screenshot', async (channel) => {
            const copy = { ...options };
            if (!copy.type)
                copy.type = elementHandle_1.determineScreenshotType(options);
            const result = await channel.screenshot(copy);
            const buffer = buffer_1.Buffer.from(result.binary, 'base64');
            if (options.path) {
                await utils_2.mkdirIfNeeded(options.path);
                await fs_1.default.promises.writeFile(options.path, buffer);
            }
            return buffer;
        });
    }
    async title() {
        return this._attributeToPage(() => this._mainFrame.title());
    }
    async bringToFront() {
        return this._wrapApiCall('page.bringToFront', async (channel) => {
            await channel.bringToFront();
        });
    }
    async close(options = { runBeforeUnload: undefined }) {
        try {
            await this._wrapApiCall('page.close', async (channel) => {
                await channel.close(options);
                if (this._ownedContext)
                    await this._ownedContext.close();
            });
        }
        catch (e) {
            if (errors_1.isSafeCloseError(e))
                return;
            throw e;
        }
    }
    isClosed() {
        return this._closed;
    }
    async click(selector, options) {
        return this._attributeToPage(() => this._mainFrame.click(selector, options));
    }
    async dblclick(selector, options) {
        return this._attributeToPage(() => this._mainFrame.dblclick(selector, options));
    }
    async tap(selector, options) {
        return this._attributeToPage(() => this._mainFrame.tap(selector, options));
    }
    async fill(selector, value, options) {
        return this._attributeToPage(() => this._mainFrame.fill(selector, value, options));
    }
    async focus(selector, options) {
        return this._attributeToPage(() => this._mainFrame.focus(selector, options));
    }
    async textContent(selector, options) {
        return this._attributeToPage(() => this._mainFrame.textContent(selector, options));
    }
    async innerText(selector, options) {
        return this._attributeToPage(() => this._mainFrame.innerText(selector, options));
    }
    async innerHTML(selector, options) {
        return this._attributeToPage(() => this._mainFrame.innerHTML(selector, options));
    }
    async getAttribute(selector, name, options) {
        return this._attributeToPage(() => this._mainFrame.getAttribute(selector, name, options));
    }
    async isChecked(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isChecked(selector, options));
    }
    async isDisabled(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isDisabled(selector, options));
    }
    async isEditable(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isEditable(selector, options));
    }
    async isEnabled(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isEnabled(selector, options));
    }
    async isHidden(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isHidden(selector, options));
    }
    async isVisible(selector, options) {
        return this._attributeToPage(() => this._mainFrame.isVisible(selector, options));
    }
    async hover(selector, options) {
        return this._attributeToPage(() => this._mainFrame.hover(selector, options));
    }
    async selectOption(selector, values, options) {
        return this._attributeToPage(() => this._mainFrame.selectOption(selector, values, options));
    }
    async setInputFiles(selector, files, options) {
        return this._attributeToPage(() => this._mainFrame.setInputFiles(selector, files, options));
    }
    async type(selector, text, options) {
        return this._attributeToPage(() => this._mainFrame.type(selector, text, options));
    }
    async press(selector, key, options) {
        return this._attributeToPage(() => this._mainFrame.press(selector, key, options));
    }
    async check(selector, options) {
        return this._attributeToPage(() => this._mainFrame.check(selector, options));
    }
    async uncheck(selector, options) {
        return this._attributeToPage(() => this._mainFrame.uncheck(selector, options));
    }
    async waitForTimeout(timeout) {
        return this._attributeToPage(() => this._mainFrame.waitForTimeout(timeout));
    }
    async waitForFunction(pageFunction, arg, options) {
        return this._attributeToPage(() => this._mainFrame.waitForFunction(pageFunction, arg, options));
    }
    workers() {
        return [...this._workers];
    }
    on(event, listener) {
        if (event === events_1.Events.Page.FileChooser && !this.listenerCount(event))
            this._channel.setFileChooserInterceptedNoReply({ intercepted: true });
        super.on(event, listener);
        return this;
    }
    addListener(event, listener) {
        if (event === events_1.Events.Page.FileChooser && !this.listenerCount(event))
            this._channel.setFileChooserInterceptedNoReply({ intercepted: true });
        super.addListener(event, listener);
        return this;
    }
    off(event, listener) {
        super.off(event, listener);
        if (event === events_1.Events.Page.FileChooser && !this.listenerCount(event))
            this._channel.setFileChooserInterceptedNoReply({ intercepted: false });
        return this;
    }
    removeListener(event, listener) {
        super.removeListener(event, listener);
        if (event === events_1.Events.Page.FileChooser && !this.listenerCount(event))
            this._channel.setFileChooserInterceptedNoReply({ intercepted: false });
        return this;
    }
    async pause() {
        return this.context()._wrapApiCall('page.pause', async (channel) => {
            await channel.pause();
        });
    }
    async pdf(options = {}) {
        return this._wrapApiCall('page.pdf', async (channel) => {
            const transportOptions = { ...options };
            if (transportOptions.margin)
                transportOptions.margin = { ...transportOptions.margin };
            if (typeof options.width === 'number')
                transportOptions.width = options.width + 'px';
            if (typeof options.height === 'number')
                transportOptions.height = options.height + 'px';
            for (const margin of ['top', 'right', 'bottom', 'left']) {
                const index = margin;
                if (options.margin && typeof options.margin[index] === 'number')
                    transportOptions.margin[index] = transportOptions.margin[index] + 'px';
            }
            const result = await channel.pdf(transportOptions);
            const buffer = buffer_1.Buffer.from(result.pdf, 'base64');
            if (options.path) {
                await fs_1.default.promises.mkdir(path_1.default.dirname(options.path), { recursive: true });
                await fs_1.default.promises.writeFile(options.path, buffer);
            }
            return buffer;
        });
    }
}
exports.Page = Page;
class BindingCall extends channelOwner_1.ChannelOwner {
    static from(channel) {
        return channel._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
    }
    async call(func) {
        try {
            const frame = frame_1.Frame.from(this._initializer.frame);
            const source = {
                context: frame._page.context(),
                page: frame._page,
                frame
            };
            let result;
            if (this._initializer.handle)
                result = await func(source, jsHandle_1.JSHandle.from(this._initializer.handle));
            else
                result = await func(source, ...this._initializer.args.map(jsHandle_1.parseResult));
            this._channel.resolve({ result: jsHandle_1.serializeArgument(result) }).catch(() => { });
        }
        catch (e) {
            this._channel.reject({ error: serializers_1.serializeError(e) }).catch(() => { });
        }
    }
}
exports.BindingCall = BindingCall;
function trimEnd(s) {
    if (s.length > 50)
        s = s.substring(0, 50) + '\u2026';
    return s;
}
function trimUrl(param) {
    if (utils_2.isRegExp(param))
        return `/${trimEnd(param.source)}/${param.flags}`;
    if (utils_2.isString(param))
        return `"${trimEnd(param)}"`;
}
//# sourceMappingURL=page.js.map