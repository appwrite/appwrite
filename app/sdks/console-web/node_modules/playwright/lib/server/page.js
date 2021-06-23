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
exports.PageBinding = exports.Worker = exports.Page = void 0;
const frames = __importStar(require("./frames"));
const input = __importStar(require("./input"));
const js = __importStar(require("./javascript"));
const network = __importStar(require("./network"));
const screenshotter_1 = require("./screenshotter");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const browserContext_1 = require("./browserContext");
const console_1 = require("./console");
const accessibility = __importStar(require("./accessibility"));
const fileChooser_1 = require("./fileChooser");
const progress_1 = require("./progress");
const utils_1 = require("../utils/utils");
const debugLogger_1 = require("../utils/debugLogger");
const instrumentation_1 = require("./instrumentation");
class Page extends instrumentation_1.SdkObject {
    constructor(delegate, browserContext) {
        super(browserContext, 'page');
        this._closedState = 'open';
        this._disconnected = false;
        this._initialized = false;
        this._pageBindings = new Map();
        this._evaluateOnNewDocumentSources = [];
        this._workers = new Map();
        this._video = null;
        this.attribution.page = this;
        this._delegate = delegate;
        this._closedCallback = () => { };
        this._closedPromise = new Promise(f => this._closedCallback = f);
        this._disconnectedCallback = () => { };
        this._disconnectedPromise = new Promise(f => this._disconnectedCallback = f);
        this._crashedCallback = () => { };
        this._crashedPromise = new Promise(f => this._crashedCallback = f);
        this._browserContext = browserContext;
        this._state = {
            emulatedSize: browserContext._options.viewport ? { viewport: browserContext._options.viewport, screen: browserContext._options.screen || browserContext._options.viewport } : null,
            mediaType: null,
            colorScheme: browserContext._options.colorScheme !== undefined ? browserContext._options.colorScheme : 'light',
            reducedMotion: browserContext._options.reducedMotion !== undefined ? browserContext._options.reducedMotion : 'no-preference',
            extraHTTPHeaders: null,
        };
        this.accessibility = new accessibility.Accessibility(delegate.getAccessibilityTree.bind(delegate));
        this.keyboard = new input.Keyboard(delegate.rawKeyboard, this);
        this.mouse = new input.Mouse(delegate.rawMouse, this);
        this.touchscreen = new input.Touchscreen(delegate.rawTouchscreen, this);
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings(browserContext._timeoutSettings);
        this._screenshotter = new screenshotter_1.Screenshotter(this);
        this._frameManager = new frames.FrameManager(this);
        if (delegate.pdf)
            this.pdf = delegate.pdf.bind(delegate);
        this.coverage = delegate.coverage ? delegate.coverage() : null;
        this.selectors = browserContext.selectors();
    }
    async initOpener(opener) {
        if (!opener)
            return;
        const openerPage = await opener.pageOrError();
        if (openerPage instanceof Page && !openerPage.isClosed())
            this._opener = openerPage;
    }
    reportAsNew(error) {
        if (error) {
            // Initialization error could have happened because of
            // context/browser closure. Just ignore the page.
            if (this._browserContext.isClosingOrClosed())
                return;
            this._setIsError(error);
        }
        this._initialized = true;
        this._browserContext.emit(browserContext_1.BrowserContext.Events.Page, this);
        // I may happen that page iniatialization finishes after Close event has already been sent,
        // in that case we fire another Close event to ensure that each reported Page will have
        // corresponding Close event after it is reported on the context.
        if (this.isClosed())
            this.emit(Page.Events.Close);
    }
    initializedOrUndefined() {
        return this._initialized ? this : undefined;
    }
    async _doSlowMo() {
        const slowMo = this._browserContext._browser.options.slowMo;
        if (!slowMo)
            return;
        await new Promise(x => setTimeout(x, slowMo));
    }
    _didClose() {
        this._frameManager.dispose();
        utils_1.assert(this._closedState !== 'closed', 'Page closed twice');
        this._closedState = 'closed';
        this.emit(Page.Events.Close);
        this._closedCallback();
    }
    _didCrash() {
        this._frameManager.dispose();
        this.emit(Page.Events.Crash);
        this._crashedCallback(new Error('Page crashed'));
    }
    _didDisconnect() {
        this._frameManager.dispose();
        utils_1.assert(!this._disconnected, 'Page disconnected twice');
        this._disconnected = true;
        this._disconnectedCallback(new Error('Page closed'));
    }
    async _onFileChooserOpened(handle) {
        let multiple;
        try {
            multiple = await handle.evaluate(element => !!element.multiple);
        }
        catch (e) {
            // Frame/context may be gone during async processing. Do not throw.
            return;
        }
        if (!this.listenerCount(Page.Events.FileChooser)) {
            handle.dispose();
            return;
        }
        const fileChooser = new fileChooser_1.FileChooser(this, handle, multiple);
        this.emit(Page.Events.FileChooser, fileChooser);
    }
    context() {
        return this._browserContext;
    }
    opener() {
        return this._opener;
    }
    mainFrame() {
        return this._frameManager.mainFrame();
    }
    frames() {
        return this._frameManager.frames();
    }
    setDefaultNavigationTimeout(timeout) {
        this._timeoutSettings.setDefaultNavigationTimeout(timeout);
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
    }
    async exposeBinding(name, needsHandle, playwrightBinding, world = 'main') {
        const identifier = PageBinding.identifier(name, world);
        if (this._pageBindings.has(identifier))
            throw new Error(`Function "${name}" has been already registered`);
        if (this._browserContext._pageBindings.has(identifier))
            throw new Error(`Function "${name}" has been already registered in the browser context`);
        const binding = new PageBinding(name, playwrightBinding, needsHandle, world);
        this._pageBindings.set(identifier, binding);
        await this._delegate.exposeBinding(binding);
    }
    setExtraHTTPHeaders(headers) {
        this._state.extraHTTPHeaders = headers;
        return this._delegate.updateExtraHTTPHeaders();
    }
    async _onBindingCalled(payload, context) {
        if (this._disconnected || this._closedState === 'closed')
            return;
        await PageBinding.dispatch(this, payload, context);
    }
    _addConsoleMessage(type, args, location, text) {
        const message = new console_1.ConsoleMessage(this, type, text, args, location);
        const intercepted = this._frameManager.interceptConsoleMessage(message);
        if (intercepted || !this.listenerCount(Page.Events.Console))
            args.forEach(arg => arg.dispose());
        else
            this.emit(Page.Events.Console, message);
    }
    async reload(metadata, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this.mainFrame().raceNavigationAction(async () => {
            // Note: waitForNavigation may fail before we get response to reload(),
            // so we should await it immediately.
            const [response] = await Promise.all([
                this.mainFrame()._waitForNavigation(progress, options),
                this._delegate.reload(),
            ]);
            await this._doSlowMo();
            return response;
        }), this._timeoutSettings.navigationTimeout(options));
    }
    async goBack(metadata, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this.mainFrame().raceNavigationAction(async () => {
            // Note: waitForNavigation may fail before we get response to goBack,
            // so we should catch it immediately.
            let error;
            const waitPromise = this.mainFrame()._waitForNavigation(progress, options).catch(e => {
                error = e;
                return null;
            });
            const result = await this._delegate.goBack();
            if (!result)
                return null;
            const response = await waitPromise;
            if (error)
                throw error;
            await this._doSlowMo();
            return response;
        }), this._timeoutSettings.navigationTimeout(options));
    }
    async goForward(metadata, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this.mainFrame().raceNavigationAction(async () => {
            // Note: waitForNavigation may fail before we get response to goForward,
            // so we should catch it immediately.
            let error;
            const waitPromise = this.mainFrame()._waitForNavigation(progress, options).catch(e => {
                error = e;
                return null;
            });
            const result = await this._delegate.goForward();
            if (!result)
                return null;
            const response = await waitPromise;
            if (error)
                throw error;
            await this._doSlowMo();
            return response;
        }), this._timeoutSettings.navigationTimeout(options));
    }
    async emulateMedia(options) {
        if (options.media !== undefined)
            this._state.mediaType = options.media;
        if (options.colorScheme !== undefined)
            this._state.colorScheme = options.colorScheme;
        if (options.reducedMotion !== undefined)
            this._state.reducedMotion = options.reducedMotion;
        await this._delegate.updateEmulateMedia();
        await this._doSlowMo();
    }
    async setViewportSize(viewportSize) {
        this._state.emulatedSize = { viewport: { ...viewportSize }, screen: { ...viewportSize } };
        await this._delegate.setEmulatedSize(this._state.emulatedSize);
        await this._doSlowMo();
    }
    viewportSize() {
        var _a;
        return ((_a = this._state.emulatedSize) === null || _a === void 0 ? void 0 : _a.viewport) || null;
    }
    async bringToFront() {
        await this._delegate.bringToFront();
    }
    async _addInitScriptExpression(source) {
        this._evaluateOnNewDocumentSources.push(source);
        await this._delegate.evaluateOnNewDocument(source);
    }
    _needsRequestInterception() {
        return !!this._clientRequestInterceptor || !!this._serverRequestInterceptor || !!this._browserContext._requestInterceptor;
    }
    async _setClientRequestInterceptor(handler) {
        this._clientRequestInterceptor = handler;
        await this._delegate.updateRequestInterception();
    }
    async _setServerRequestInterceptor(handler) {
        this._serverRequestInterceptor = handler;
        await this._delegate.updateRequestInterception();
    }
    _requestStarted(request) {
        const route = request._route();
        if (!route)
            return;
        if (this._serverRequestInterceptor) {
            this._serverRequestInterceptor(route, request);
            return;
        }
        if (this._clientRequestInterceptor) {
            this._clientRequestInterceptor(route, request);
            return;
        }
        if (this._browserContext._requestInterceptor) {
            this._browserContext._requestInterceptor(route, request);
            return;
        }
        route.continue();
    }
    async screenshot(metadata, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this._screenshotter.screenshotPage(progress, options), this._timeoutSettings.timeout(options));
    }
    async close(metadata, options) {
        if (this._closedState === 'closed')
            return;
        const runBeforeUnload = !!options && !!options.runBeforeUnload;
        if (this._closedState !== 'closing') {
            this._closedState = 'closing';
            utils_1.assert(!this._disconnected, 'Protocol error: Connection closed. Most likely the page has been closed.');
            // This might throw if the browser context containing the page closes
            // while we are trying to close the page.
            await this._delegate.closePage(runBeforeUnload).catch(e => debugLogger_1.debugLogger.log('error', e));
        }
        if (!runBeforeUnload)
            await this._closedPromise;
        if (this._ownedContext)
            await this._ownedContext.close(metadata);
    }
    _setIsError(error) {
        this._pageIsError = error;
        if (!this._frameManager.mainFrame())
            this._frameManager.frameAttached('<dummy>', null);
    }
    isClosed() {
        return this._closedState === 'closed';
    }
    _addWorker(workerId, worker) {
        this._workers.set(workerId, worker);
        this.emit(Page.Events.Worker, worker);
    }
    _removeWorker(workerId) {
        const worker = this._workers.get(workerId);
        if (!worker)
            return;
        worker.emit(Worker.Events.Close, worker);
        this._workers.delete(workerId);
    }
    _clearWorkers() {
        for (const [workerId, worker] of this._workers) {
            worker.emit(Worker.Events.Close, worker);
            this._workers.delete(workerId);
        }
    }
    async _setFileChooserIntercepted(enabled) {
        await this._delegate.setFileChooserIntercepted(enabled);
    }
    frameNavigatedToNewDocument(frame) {
        this.emit(Page.Events.InternalFrameNavigatedToNewDocument, frame);
        const url = frame.url();
        if (!url.startsWith('http'))
            return;
        const purl = network.parsedURL(url);
        if (purl)
            this._browserContext.addVisitedOrigin(purl.origin);
    }
    allBindings() {
        return [...this._browserContext._pageBindings.values(), ...this._pageBindings.values()];
    }
    getBinding(name, world) {
        const identifier = PageBinding.identifier(name, world);
        return this._pageBindings.get(identifier) || this._browserContext._pageBindings.get(identifier);
    }
    setScreencastOptions(options) {
        this._delegate.setScreencastOptions(options).catch(e => debugLogger_1.debugLogger.log('error', e));
    }
}
exports.Page = Page;
Page.Events = {
    Close: 'close',
    Crash: 'crash',
    Console: 'console',
    Dialog: 'dialog',
    Download: 'download',
    FileChooser: 'filechooser',
    DOMContentLoaded: 'domcontentloaded',
    // Can't use just 'error' due to node.js special treatment of error events.
    // @see https://nodejs.org/api/events.html#events_error_events
    PageError: 'pageerror',
    FrameAttached: 'frameattached',
    FrameDetached: 'framedetached',
    InternalFrameNavigatedToNewDocument: 'internalframenavigatedtonewdocument',
    Load: 'load',
    ScreencastFrame: 'screencastframe',
    Video: 'video',
    WebSocket: 'websocket',
    Worker: 'worker',
};
class Worker extends instrumentation_1.SdkObject {
    constructor(parent, url) {
        super(parent, 'worker');
        this._existingExecutionContext = null;
        this._url = url;
        this._executionContextCallback = () => { };
        this._executionContextPromise = new Promise(x => this._executionContextCallback = x);
    }
    _createExecutionContext(delegate) {
        this._existingExecutionContext = new js.ExecutionContext(this, delegate);
        this._executionContextCallback(this._existingExecutionContext);
    }
    url() {
        return this._url;
    }
    async evaluateExpression(expression, isFunction, arg) {
        return js.evaluateExpression(await this._executionContextPromise, true /* returnByValue */, expression, isFunction, arg);
    }
    async evaluateExpressionHandle(expression, isFunction, arg) {
        return js.evaluateExpression(await this._executionContextPromise, false /* returnByValue */, expression, isFunction, arg);
    }
}
exports.Worker = Worker;
Worker.Events = {
    Close: 'close',
};
class PageBinding {
    constructor(name, playwrightFunction, needsHandle, world) {
        this.name = name;
        this.playwrightFunction = playwrightFunction;
        this.source = `(${addPageBinding.toString()})(${JSON.stringify(name)}, ${needsHandle})`;
        this.needsHandle = needsHandle;
        this.world = world;
    }
    static identifier(name, world) {
        return world + ':' + name;
    }
    static async dispatch(page, payload, context) {
        const { name, seq, args } = JSON.parse(payload);
        try {
            utils_1.assert(context.world);
            const binding = page.getBinding(name, context.world);
            let result;
            if (binding.needsHandle) {
                const handle = await context.evaluateHandle(takeHandle, { name, seq }).catch(e => null);
                result = await binding.playwrightFunction({ frame: context.frame, page, context: page._browserContext }, handle);
            }
            else {
                result = await binding.playwrightFunction({ frame: context.frame, page, context: page._browserContext }, ...args);
            }
            context.evaluate(deliverResult, { name, seq, result }).catch(e => debugLogger_1.debugLogger.log('error', e));
        }
        catch (error) {
            if (utils_1.isError(error))
                context.evaluate(deliverError, { name, seq, message: error.message, stack: error.stack }).catch(e => debugLogger_1.debugLogger.log('error', e));
            else
                context.evaluate(deliverErrorValue, { name, seq, error }).catch(e => debugLogger_1.debugLogger.log('error', e));
        }
        function takeHandle(arg) {
            const handle = globalThis[arg.name]['handles'].get(arg.seq);
            globalThis[arg.name]['handles'].delete(arg.seq);
            return handle;
        }
        function deliverResult(arg) {
            globalThis[arg.name]['callbacks'].get(arg.seq).resolve(arg.result);
            globalThis[arg.name]['callbacks'].delete(arg.seq);
        }
        function deliverError(arg) {
            const error = new Error(arg.message);
            error.stack = arg.stack;
            globalThis[arg.name]['callbacks'].get(arg.seq).reject(error);
            globalThis[arg.name]['callbacks'].delete(arg.seq);
        }
        function deliverErrorValue(arg) {
            globalThis[arg.name]['callbacks'].get(arg.seq).reject(arg.error);
            globalThis[arg.name]['callbacks'].delete(arg.seq);
        }
    }
}
exports.PageBinding = PageBinding;
function addPageBinding(bindingName, needsHandle) {
    const binding = globalThis[bindingName];
    if (binding.__installed)
        return;
    globalThis[bindingName] = (...args) => {
        const me = globalThis[bindingName];
        if (needsHandle && args.slice(1).some(arg => arg !== undefined))
            throw new Error(`exposeBindingHandle supports a single argument, ${args.length} received`);
        let callbacks = me['callbacks'];
        if (!callbacks) {
            callbacks = new Map();
            me['callbacks'] = callbacks;
        }
        const seq = (me['lastSeq'] || 0) + 1;
        me['lastSeq'] = seq;
        let handles = me['handles'];
        if (!handles) {
            handles = new Map();
            me['handles'] = handles;
        }
        const promise = new Promise((resolve, reject) => callbacks.set(seq, { resolve, reject }));
        if (needsHandle) {
            handles.set(seq, args[0]);
            binding(JSON.stringify({ name: bindingName, seq }));
        }
        else {
            binding(JSON.stringify({ name: bindingName, seq, args }));
        }
        return promise;
    };
    globalThis[bindingName].__installed = true;
}
//# sourceMappingURL=page.js.map