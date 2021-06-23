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
exports.Frame = exports.FrameManager = void 0;
const dom = __importStar(require("./dom"));
const helper_1 = require("./helper");
const js = __importStar(require("./javascript"));
const network = __importStar(require("./network"));
const page_1 = require("./page");
const types = __importStar(require("./types"));
const browserContext_1 = require("./browserContext");
const progress_1 = require("./progress");
const utils_1 = require("../utils/utils");
const debugLogger_1 = require("../utils/debugLogger");
const instrumentation_1 = require("./instrumentation");
class FrameManager {
    constructor(page) {
        this._frames = new Map();
        this._consoleMessageTags = new Map();
        this._signalBarriers = new Set();
        this._webSockets = new Map();
        this._responses = [];
        this._page = page;
        this._mainFrame = undefined;
    }
    dispose() {
        for (const frame of this._frames.values())
            frame._stopNetworkIdleTimer();
    }
    mainFrame() {
        return this._mainFrame;
    }
    frames() {
        const frames = [];
        collect(this._mainFrame);
        return frames;
        function collect(frame) {
            frames.push(frame);
            for (const subframe of frame.childFrames())
                collect(subframe);
        }
    }
    frame(frameId) {
        return this._frames.get(frameId) || null;
    }
    frameAttached(frameId, parentFrameId) {
        const parentFrame = parentFrameId ? this._frames.get(parentFrameId) : null;
        if (!parentFrame) {
            if (this._mainFrame) {
                // Update frame id to retain frame identity on cross-process navigation.
                this._frames.delete(this._mainFrame._id);
                this._mainFrame._id = frameId;
            }
            else {
                utils_1.assert(!this._frames.has(frameId));
                this._mainFrame = new Frame(this._page, frameId, parentFrame);
            }
            this._frames.set(frameId, this._mainFrame);
            return this._mainFrame;
        }
        else {
            utils_1.assert(!this._frames.has(frameId));
            const frame = new Frame(this._page, frameId, parentFrame);
            this._frames.set(frameId, frame);
            this._page.emit(page_1.Page.Events.FrameAttached, frame);
            return frame;
        }
    }
    async waitForSignalsCreatedBy(progress, noWaitAfter, action, source) {
        if (noWaitAfter)
            return action();
        const barrier = new SignalBarrier(progress);
        this._signalBarriers.add(barrier);
        if (progress)
            progress.cleanupWhenAborted(() => this._signalBarriers.delete(barrier));
        const result = await action();
        if (source === 'input')
            await this._page._delegate.inputActionEpilogue();
        await barrier.waitFor();
        this._signalBarriers.delete(barrier);
        // Resolve in the next task, after all waitForNavigations.
        await new Promise(utils_1.makeWaitForNextTask());
        return result;
    }
    frameWillPotentiallyRequestNavigation() {
        for (const barrier of this._signalBarriers)
            barrier.retain();
    }
    frameDidPotentiallyRequestNavigation() {
        for (const barrier of this._signalBarriers)
            barrier.release();
    }
    frameRequestedNavigation(frameId, documentId) {
        const frame = this._frames.get(frameId);
        if (!frame)
            return;
        for (const barrier of this._signalBarriers)
            barrier.addFrameNavigation(frame);
        if (frame.pendingDocument() && frame.pendingDocument().documentId === documentId) {
            // Do not override request with undefined.
            return;
        }
        frame.setPendingDocument({ documentId, request: undefined });
    }
    frameCommittedNewDocumentNavigation(frameId, url, name, documentId, initial) {
        const frame = this._frames.get(frameId);
        this.removeChildFramesRecursively(frame);
        this.clearWebSockets(frame);
        frame._url = url;
        frame._name = name;
        let keepPending;
        const pendingDocument = frame.pendingDocument();
        if (pendingDocument) {
            if (pendingDocument.documentId === undefined) {
                // Pending with unknown documentId - assume it is the one being committed.
                pendingDocument.documentId = documentId;
            }
            if (pendingDocument.documentId === documentId) {
                // Committing a pending document.
                frame._currentDocument = pendingDocument;
            }
            else {
                // Sometimes, we already have a new pending when the old one commits.
                // An example would be Chromium error page followed by a new navigation request,
                // where the error page commit arrives after Network.requestWillBeSent for the
                // new navigation.
                // We commit, but keep the pending request since it's not done yet.
                keepPending = pendingDocument;
                frame._currentDocument = { documentId, request: undefined };
            }
            frame.setPendingDocument(undefined);
        }
        else {
            // No pending - just commit a new document.
            frame._currentDocument = { documentId, request: undefined };
        }
        frame._onClearLifecycle();
        const navigationEvent = { url, name, newDocument: frame._currentDocument };
        frame.emit(Frame.Events.Navigation, navigationEvent);
        this._responses.length = 0;
        if (!initial) {
            debugLogger_1.debugLogger.log('api', `  navigated to "${url}"`);
            this._page.frameNavigatedToNewDocument(frame);
        }
        // Restore pending if any - see comments above about keepPending.
        frame.setPendingDocument(keepPending);
    }
    frameCommittedSameDocumentNavigation(frameId, url) {
        const frame = this._frames.get(frameId);
        if (!frame)
            return;
        frame._url = url;
        const navigationEvent = { url, name: frame._name };
        frame.emit(Frame.Events.Navigation, navigationEvent);
        debugLogger_1.debugLogger.log('api', `  navigated to "${url}"`);
    }
    frameAbortedNavigation(frameId, errorText, documentId) {
        const frame = this._frames.get(frameId);
        if (!frame || !frame.pendingDocument())
            return;
        if (documentId !== undefined && frame.pendingDocument().documentId !== documentId)
            return;
        const navigationEvent = {
            url: frame._url,
            name: frame._name,
            newDocument: frame.pendingDocument(),
            error: new Error(errorText),
        };
        frame.setPendingDocument(undefined);
        frame.emit(Frame.Events.Navigation, navigationEvent);
    }
    frameDetached(frameId) {
        const frame = this._frames.get(frameId);
        if (frame)
            this._removeFramesRecursively(frame);
    }
    frameStoppedLoading(frameId) {
        this.frameLifecycleEvent(frameId, 'domcontentloaded');
        this.frameLifecycleEvent(frameId, 'load');
    }
    frameLifecycleEvent(frameId, event) {
        const frame = this._frames.get(frameId);
        if (frame)
            frame._onLifecycleEvent(event);
    }
    requestStarted(request) {
        const frame = request.frame();
        this._inflightRequestStarted(request);
        if (request._documentId)
            frame.setPendingDocument({ documentId: request._documentId, request });
        if (request._isFavicon) {
            const route = request._route();
            if (route)
                route.continue();
            return;
        }
        this._page._browserContext.emit(browserContext_1.BrowserContext.Events.Request, request);
        this._page._requestStarted(request);
    }
    requestReceivedResponse(response) {
        if (response.request()._isFavicon)
            return;
        this._responses.push(response);
        this._page._browserContext.emit(browserContext_1.BrowserContext.Events.Response, response);
    }
    requestFinished(request) {
        this._inflightRequestFinished(request);
        if (request._isFavicon)
            return;
        this._page._browserContext.emit(browserContext_1.BrowserContext.Events.RequestFinished, request);
    }
    requestFailed(request, canceled) {
        const frame = request.frame();
        this._inflightRequestFinished(request);
        if (frame.pendingDocument() && frame.pendingDocument().request === request) {
            let errorText = request.failure().errorText;
            if (canceled)
                errorText += '; maybe frame was detached?';
            this.frameAbortedNavigation(frame._id, errorText, frame.pendingDocument().documentId);
        }
        if (request._isFavicon)
            return;
        this._page._browserContext.emit(browserContext_1.BrowserContext.Events.RequestFailed, request);
    }
    removeChildFramesRecursively(frame) {
        for (const child of frame.childFrames())
            this._removeFramesRecursively(child);
    }
    _removeFramesRecursively(frame) {
        this.removeChildFramesRecursively(frame);
        frame._onDetached();
        this._frames.delete(frame._id);
        if (!this._page.isClosed())
            this._page.emit(page_1.Page.Events.FrameDetached, frame);
    }
    _inflightRequestFinished(request) {
        const frame = request.frame();
        if (request._isFavicon)
            return;
        if (!frame._inflightRequests.has(request))
            return;
        frame._inflightRequests.delete(request);
        if (frame._inflightRequests.size === 0)
            frame._startNetworkIdleTimer();
    }
    _inflightRequestStarted(request) {
        const frame = request.frame();
        if (request._isFavicon)
            return;
        frame._inflightRequests.add(request);
        if (frame._inflightRequests.size === 1)
            frame._stopNetworkIdleTimer();
    }
    interceptConsoleMessage(message) {
        if (message.type() !== 'debug')
            return false;
        const tag = message.text();
        const handler = this._consoleMessageTags.get(tag);
        if (!handler)
            return false;
        this._consoleMessageTags.delete(tag);
        handler();
        return true;
    }
    clearWebSockets(frame) {
        // TODO: attribute sockets to frames.
        if (frame.parentFrame())
            return;
        this._webSockets.clear();
    }
    onWebSocketCreated(requestId, url) {
        const ws = new network.WebSocket(this._page, url);
        this._webSockets.set(requestId, ws);
    }
    onWebSocketRequest(requestId) {
        const ws = this._webSockets.get(requestId);
        if (ws)
            this._page.emit(page_1.Page.Events.WebSocket, ws);
    }
    onWebSocketResponse(requestId, status, statusText) {
        const ws = this._webSockets.get(requestId);
        if (status < 400)
            return;
        if (ws)
            ws.error(`${statusText}: ${status}`);
    }
    onWebSocketFrameSent(requestId, opcode, data) {
        const ws = this._webSockets.get(requestId);
        if (ws)
            ws.frameSent(opcode, data);
    }
    webSocketFrameReceived(requestId, opcode, data) {
        const ws = this._webSockets.get(requestId);
        if (ws)
            ws.frameReceived(opcode, data);
    }
    webSocketClosed(requestId) {
        const ws = this._webSockets.get(requestId);
        if (ws)
            ws.closed();
        this._webSockets.delete(requestId);
    }
    webSocketError(requestId, errorMessage) {
        const ws = this._webSockets.get(requestId);
        if (ws)
            ws.error(errorMessage);
    }
}
exports.FrameManager = FrameManager;
class Frame extends instrumentation_1.SdkObject {
    constructor(page, id, parentFrame) {
        super(page, 'frame');
        this._firedLifecycleEvents = new Set();
        this._subtreeLifecycleEvents = new Set();
        this._url = '';
        this._detached = false;
        this._contextData = new Map();
        this._childFrames = new Set();
        this._name = '';
        this._inflightRequests = new Set();
        this._setContentCounter = 0;
        this._detachedCallback = () => { };
        this._nonStallingEvaluations = new Set();
        this.attribution.frame = this;
        this._id = id;
        this._page = page;
        this._parentFrame = parentFrame;
        this._currentDocument = { documentId: undefined, request: undefined };
        this._detachedPromise = new Promise(x => this._detachedCallback = x);
        this._contextData.set('main', { contextPromise: new Promise(() => { }), contextResolveCallback: () => { }, context: null, rerunnableTasks: new Set() });
        this._contextData.set('utility', { contextPromise: new Promise(() => { }), contextResolveCallback: () => { }, context: null, rerunnableTasks: new Set() });
        this._setContext('main', null);
        this._setContext('utility', null);
        if (this._parentFrame)
            this._parentFrame._childFrames.add(this);
    }
    _onLifecycleEvent(event) {
        if (this._firedLifecycleEvents.has(event))
            return;
        this._firedLifecycleEvents.add(event);
        // Recalculate subtree lifecycle for the whole tree - it should not be that big.
        this._page.mainFrame()._recalculateLifecycle();
    }
    _onClearLifecycle() {
        this._firedLifecycleEvents.clear();
        // Recalculate subtree lifecycle for the whole tree - it should not be that big.
        this._page.mainFrame()._recalculateLifecycle();
        // Keep the current navigation request if any.
        this._inflightRequests = new Set(Array.from(this._inflightRequests).filter(request => request === this._currentDocument.request));
        this._stopNetworkIdleTimer();
        if (this._inflightRequests.size === 0)
            this._startNetworkIdleTimer();
    }
    setPendingDocument(documentInfo) {
        this._pendingDocument = documentInfo;
        if (documentInfo)
            this._invalidateNonStallingEvaluations();
    }
    pendingDocument() {
        return this._pendingDocument;
    }
    async _invalidateNonStallingEvaluations() {
        if (!this._nonStallingEvaluations)
            return;
        const error = new Error('Navigation interrupted the evaluation');
        for (const callback of this._nonStallingEvaluations)
            callback(error);
    }
    async nonStallingRawEvaluateInExistingMainContext(expression) {
        if (this._pendingDocument)
            throw new Error('Frame is currently attempting a navigation');
        const context = this._existingMainContext();
        if (!context)
            throw new Error('Frame does not yet have a main execution context');
        let callback = () => { };
        const frameInvalidated = new Promise((f, r) => callback = r);
        this._nonStallingEvaluations.add(callback);
        try {
            return await Promise.race([
                context.rawEvaluateJSON(expression),
                frameInvalidated
            ]);
        }
        finally {
            this._nonStallingEvaluations.delete(callback);
        }
    }
    async nonStallingEvaluateInExistingContext(expression, isFunction, world) {
        var _a;
        if (this._pendingDocument)
            throw new Error('Frame is currently attempting a navigation');
        const context = (_a = this._contextData.get(world)) === null || _a === void 0 ? void 0 : _a.context;
        if (!context)
            throw new Error('Frame does not yet have the execution context');
        let callback = () => { };
        const frameInvalidated = new Promise((f, r) => callback = r);
        this._nonStallingEvaluations.add(callback);
        try {
            return await Promise.race([
                context.evaluateExpression(expression, isFunction),
                frameInvalidated
            ]);
        }
        finally {
            this._nonStallingEvaluations.delete(callback);
        }
    }
    _recalculateLifecycle() {
        const events = new Set(this._firedLifecycleEvents);
        for (const child of this._childFrames) {
            child._recalculateLifecycle();
            // We require a particular lifecycle event to be fired in the whole
            // frame subtree, and then consider it done.
            for (const event of events) {
                if (!child._subtreeLifecycleEvents.has(event))
                    events.delete(event);
            }
        }
        const mainFrame = this._page.mainFrame();
        for (const event of events) {
            // Checking whether we have already notified about this event.
            if (!this._subtreeLifecycleEvents.has(event)) {
                this.emit(Frame.Events.AddLifecycle, event);
                if (this === mainFrame && this._url !== 'about:blank')
                    debugLogger_1.debugLogger.log('api', `  "${event}" event fired`);
                if (this === mainFrame && event === 'load')
                    this._page.emit(page_1.Page.Events.Load);
                if (this === mainFrame && event === 'domcontentloaded')
                    this._page.emit(page_1.Page.Events.DOMContentLoaded);
            }
        }
        for (const event of this._subtreeLifecycleEvents) {
            if (!events.has(event))
                this.emit(Frame.Events.RemoveLifecycle, event);
        }
        this._subtreeLifecycleEvents = events;
    }
    async raceNavigationAction(action) {
        return Promise.race([
            this._page._disconnectedPromise.then(() => { throw new Error('Navigation failed because page was closed!'); }),
            this._page._crashedPromise.then(() => { throw new Error('Navigation failed because page crashed!'); }),
            this._detachedPromise.then(() => { throw new Error('Navigating frame was detached!'); }),
            action(),
        ]);
    }
    async goto(metadata, url, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this._goto(progress, url, options), this._page._timeoutSettings.navigationTimeout(options));
    }
    async _goto(progress, url, options) {
        return this.raceNavigationAction(async () => {
            const waitUntil = verifyLifecycle('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            progress.log(`navigating to "${url}", waiting until "${waitUntil}"`);
            const headers = this._page._state.extraHTTPHeaders || [];
            const refererHeader = headers.find(h => h.name.toLowerCase() === 'referer');
            let referer = refererHeader ? refererHeader.value : undefined;
            if (options.referer !== undefined) {
                if (referer !== undefined && referer !== options.referer)
                    throw new Error('"referer" is already specified as extra HTTP header');
                referer = options.referer;
            }
            url = helper_1.helper.completeUserURL(url);
            const sameDocument = helper_1.helper.waitForEvent(progress, this, Frame.Events.Navigation, (e) => !e.newDocument);
            const navigateResult = await this._page._delegate.navigateFrame(this, url, referer);
            let event;
            if (navigateResult.newDocumentId) {
                sameDocument.dispose();
                event = await helper_1.helper.waitForEvent(progress, this, Frame.Events.Navigation, (event) => {
                    // We are interested either in this specific document, or any other document that
                    // did commit and replaced the expected document.
                    return event.newDocument && (event.newDocument.documentId === navigateResult.newDocumentId || !event.error);
                }).promise;
                if (event.newDocument.documentId !== navigateResult.newDocumentId) {
                    // This is just a sanity check. In practice, new navigation should
                    // cancel the previous one and report "request cancelled"-like error.
                    throw new Error('Navigation interrupted by another one');
                }
                if (event.error)
                    throw event.error;
            }
            else {
                event = await sameDocument.promise;
            }
            if (!this._subtreeLifecycleEvents.has(waitUntil))
                await helper_1.helper.waitForEvent(progress, this, Frame.Events.AddLifecycle, (e) => e === waitUntil).promise;
            const request = event.newDocument ? event.newDocument.request : undefined;
            const response = request ? request._finalRequest().response() : null;
            await this._page._doSlowMo();
            return response;
        });
    }
    async _waitForNavigation(progress, options) {
        const waitUntil = verifyLifecycle('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
        progress.log(`waiting for navigation until "${waitUntil}"`);
        const navigationEvent = await helper_1.helper.waitForEvent(progress, this, Frame.Events.Navigation, (event) => {
            // Any failed navigation results in a rejection.
            if (event.error)
                return true;
            progress.log(`  navigated to "${this._url}"`);
            return true;
        }).promise;
        if (navigationEvent.error)
            throw navigationEvent.error;
        if (!this._subtreeLifecycleEvents.has(waitUntil))
            await helper_1.helper.waitForEvent(progress, this, Frame.Events.AddLifecycle, (e) => e === waitUntil).promise;
        const request = navigationEvent.newDocument ? navigationEvent.newDocument.request : undefined;
        return request ? request._finalRequest().response() : null;
    }
    async _waitForLoadState(progress, state) {
        const waitUntil = verifyLifecycle('state', state);
        if (!this._subtreeLifecycleEvents.has(waitUntil))
            await helper_1.helper.waitForEvent(progress, this, Frame.Events.AddLifecycle, (e) => e === waitUntil).promise;
    }
    async frameElement() {
        return this._page._delegate.getFrameElement(this);
    }
    _context(world) {
        if (this._detached)
            throw new Error(`Execution Context is not available in detached frame "${this.url()}" (are you trying to evaluate?)`);
        return this._contextData.get(world).contextPromise;
    }
    _mainContext() {
        return this._context('main');
    }
    _existingMainContext() {
        var _a;
        return ((_a = this._contextData.get('main')) === null || _a === void 0 ? void 0 : _a.context) || null;
    }
    _utilityContext() {
        return this._context('utility');
    }
    async evaluateExpressionHandleAndWaitForSignals(expression, isFunction, arg, world = 'main') {
        const context = await this._context(world);
        const handle = await context.evaluateExpressionHandleAndWaitForSignals(expression, isFunction, arg);
        if (world === 'main')
            await this._page._doSlowMo();
        return handle;
    }
    async evaluateExpression(expression, isFunction, arg, world = 'main') {
        const context = await this._context(world);
        const value = await context.evaluateExpression(expression, isFunction, arg);
        if (world === 'main')
            await this._page._doSlowMo();
        return value;
    }
    async evaluateExpressionAndWaitForSignals(expression, isFunction, arg, world = 'main') {
        const context = await this._context(world);
        const value = await context.evaluateExpressionAndWaitForSignals(expression, isFunction, arg);
        if (world === 'main')
            await this._page._doSlowMo();
        return value;
    }
    async $(selector) {
        return this._page.selectors._query(this, selector);
    }
    async waitForSelector(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        if (options.visibility)
            throw new Error('options.visibility is not supported, did you mean options.state?');
        if (options.waitFor && options.waitFor !== 'visible')
            throw new Error('options.waitFor is not supported, did you mean options.state?');
        const { state = 'visible' } = options;
        if (!['attached', 'detached', 'visible', 'hidden'].includes(state))
            throw new Error(`state: expected one of (attached|detached|visible|hidden)`);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.waitForSelectorTask(info, state);
        return controller.run(async (progress) => {
            progress.log(`waiting for selector "${selector}"${state === 'attached' ? '' : ' to be ' + state}`);
            while (progress.isRunning()) {
                const result = await this._scheduleRerunnableHandleTask(progress, info.world, task);
                if (!result.asElement()) {
                    result.dispose();
                    return null;
                }
                if (options.__testHookBeforeAdoptNode)
                    await options.__testHookBeforeAdoptNode();
                try {
                    const handle = result.asElement();
                    const adopted = await handle._adoptTo(await this._mainContext());
                    return adopted;
                }
                catch (e) {
                    // Navigated while trying to adopt the node.
                    if (!js.isContextDestroyedError(e) && !e.message.includes(dom.kUnableToAdoptErrorMessage))
                        throw e;
                    result.dispose();
                }
            }
            return null;
        }, this._page._timeoutSettings.timeout(options));
    }
    async dispatchEvent(metadata, selector, type, eventInit, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.dispatchEventTask(info, type, eventInit || {});
        await controller.run(async (progress) => {
            progress.log(`Dispatching "${type}" event on selector "${selector}"...`);
            // Note: we always dispatch events in the main world.
            await this._scheduleRerunnableTask(progress, 'main', task);
        }, this._page._timeoutSettings.timeout(options));
        await this._page._doSlowMo();
    }
    async evalOnSelectorAndWaitForSignals(selector, expression, isFunction, arg) {
        const handle = await this.$(selector);
        if (!handle)
            throw new Error(`Error: failed to find element matching selector "${selector}"`);
        const result = await handle.evaluateExpressionAndWaitForSignals(expression, isFunction, true, arg);
        handle.dispose();
        return result;
    }
    async evalOnSelectorAllAndWaitForSignals(selector, expression, isFunction, arg) {
        const arrayHandle = await this._page.selectors._queryArray(this, selector);
        const result = await arrayHandle.evaluateExpressionAndWaitForSignals(expression, isFunction, true, arg);
        arrayHandle.dispose();
        return result;
    }
    async $$(selector) {
        return this._page.selectors._queryAll(this, selector, undefined, true /* adoptToMain */);
    }
    async content() {
        const context = await this._utilityContext();
        return context.evaluate(() => {
            let retVal = '';
            if (document.doctype)
                retVal = new XMLSerializer().serializeToString(document.doctype);
            if (document.documentElement)
                retVal += document.documentElement.outerHTML;
            return retVal;
        });
    }
    async setContent(metadata, html, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(progress => this.raceNavigationAction(async () => {
            const waitUntil = options.waitUntil === undefined ? 'load' : options.waitUntil;
            progress.log(`setting frame content, waiting until "${waitUntil}"`);
            const tag = `--playwright--set--content--${this._id}--${++this._setContentCounter}--`;
            const context = await this._utilityContext();
            const lifecyclePromise = new Promise((resolve, reject) => {
                this._page._frameManager._consoleMessageTags.set(tag, () => {
                    // Clear lifecycle right after document.open() - see 'tag' below.
                    this._onClearLifecycle();
                    this._waitForLoadState(progress, waitUntil).then(resolve).catch(reject);
                });
            });
            const contentPromise = context.evaluate(({ html, tag }) => {
                window.stop();
                document.open();
                console.debug(tag); // eslint-disable-line no-console
                document.write(html);
                document.close();
            }, { html, tag });
            await Promise.all([contentPromise, lifecyclePromise]);
            await this._page._doSlowMo();
        }), this._page._timeoutSettings.navigationTimeout(options));
    }
    name() {
        return this._name || '';
    }
    url() {
        return this._url;
    }
    parentFrame() {
        return this._parentFrame;
    }
    childFrames() {
        return Array.from(this._childFrames);
    }
    async addScriptTag(params) {
        const { url = null, content = null, type = '' } = params;
        if (!url && !content)
            throw new Error('Provide an object with a `url`, `path` or `content` property');
        const context = await this._mainContext();
        return this._raceWithCSPError(async () => {
            if (url !== null)
                return (await context.evaluateHandle(addScriptUrl, { url, type })).asElement();
            const result = (await context.evaluateHandle(addScriptContent, { content: content, type })).asElement();
            // Another round trip to the browser to ensure that we receive CSP error messages
            // (if any) logged asynchronously in a separate task on the content main thread.
            if (this._page._delegate.cspErrorsAsynchronousForInlineScipts)
                await context.evaluate(() => true);
            return result;
        });
        async function addScriptUrl(params) {
            const script = document.createElement('script');
            script.src = params.url;
            if (params.type)
                script.type = params.type;
            const promise = new Promise((res, rej) => {
                script.onload = res;
                script.onerror = e => rej(typeof e === 'string' ? new Error(e) : new Error(`Failed to load script at ${script.src}`));
            });
            document.head.appendChild(script);
            await promise;
            return script;
        }
        function addScriptContent(params) {
            const script = document.createElement('script');
            script.type = params.type || 'text/javascript';
            script.text = params.content;
            let error = null;
            script.onerror = e => error = e;
            document.head.appendChild(script);
            if (error)
                throw error;
            return script;
        }
    }
    async addStyleTag(params) {
        const { url = null, content = null } = params;
        if (!url && !content)
            throw new Error('Provide an object with a `url`, `path` or `content` property');
        const context = await this._mainContext();
        return this._raceWithCSPError(async () => {
            if (url !== null)
                return (await context.evaluateHandle(addStyleUrl, url)).asElement();
            return (await context.evaluateHandle(addStyleContent, content)).asElement();
        });
        async function addStyleUrl(url) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;
            const promise = new Promise((res, rej) => {
                link.onload = res;
                link.onerror = rej;
            });
            document.head.appendChild(link);
            await promise;
            return link;
        }
        async function addStyleContent(content) {
            const style = document.createElement('style');
            style.type = 'text/css';
            style.appendChild(document.createTextNode(content));
            const promise = new Promise((res, rej) => {
                style.onload = res;
                style.onerror = rej;
            });
            document.head.appendChild(style);
            await promise;
            return style;
        }
    }
    async _raceWithCSPError(func) {
        const listeners = [];
        let result;
        let error;
        let cspMessage;
        const actionPromise = new Promise(async (resolve) => {
            try {
                result = await func();
            }
            catch (e) {
                error = e;
            }
            resolve();
        });
        const errorPromise = new Promise(resolve => {
            listeners.push(helper_1.helper.addEventListener(this._page, page_1.Page.Events.Console, (message) => {
                if (message.type() === 'error' && message.text().includes('Content Security Policy')) {
                    cspMessage = message;
                    resolve();
                }
            }));
        });
        await Promise.race([actionPromise, errorPromise]);
        helper_1.helper.removeEventListeners(listeners);
        if (cspMessage)
            throw new Error(cspMessage.text());
        if (error)
            throw error;
        return result;
    }
    async _retryWithProgressIfNotConnected(progress, selector, action) {
        const info = this._page.selectors._parseSelector(selector);
        while (progress.isRunning()) {
            progress.log(`waiting for selector "${selector}"`);
            const task = dom.waitForSelectorTask(info, 'attached');
            const handle = await this._scheduleRerunnableHandleTask(progress, info.world, task);
            const element = handle.asElement();
            progress.cleanupWhenAborted(() => {
                // Do not await here to avoid being blocked, either by stalled
                // page (e.g. alert) or unresolved navigation in Chromium.
                element.dispose();
            });
            const result = await action(element);
            element.dispose();
            if (result === 'error:notconnected') {
                progress.log('element was detached from the DOM, retrying');
                continue;
            }
            return result;
        }
        return undefined;
    }
    async _retryWithSelectorIfNotConnected(controller, selector, options, action) {
        return controller.run(async (progress) => {
            return this._retryWithProgressIfNotConnected(progress, selector, handle => action(progress, handle));
        }, this._page._timeoutSettings.timeout(options));
    }
    async click(metadata, selector, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._click(progress, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async dblclick(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._dblclick(progress, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async tap(metadata, selector, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._tap(progress, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async fill(metadata, selector, value, options) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._fill(progress, value, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async focus(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        await this._retryWithSelectorIfNotConnected(controller, selector, options, (progress, handle) => handle._focus(progress));
        await this._page._doSlowMo();
    }
    async textContent(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.textContentTask(info);
        return controller.run(async (progress) => {
            progress.log(`  retrieving textContent from "${selector}"`);
            return this._scheduleRerunnableTask(progress, info.world, task);
        }, this._page._timeoutSettings.timeout(options));
    }
    async innerText(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.innerTextTask(info);
        return controller.run(async (progress) => {
            progress.log(`  retrieving innerText from "${selector}"`);
            const result = dom.throwFatalDOMError(await this._scheduleRerunnableTask(progress, info.world, task));
            return result.innerText;
        }, this._page._timeoutSettings.timeout(options));
    }
    async innerHTML(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.innerHTMLTask(info);
        return controller.run(async (progress) => {
            progress.log(`  retrieving innerHTML from "${selector}"`);
            return this._scheduleRerunnableTask(progress, info.world, task);
        }, this._page._timeoutSettings.timeout(options));
    }
    async getAttribute(metadata, selector, name, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.getAttributeTask(info, name);
        return controller.run(async (progress) => {
            progress.log(`  retrieving attribute "${name}" from "${selector}"`);
            return this._scheduleRerunnableTask(progress, info.world, task);
        }, this._page._timeoutSettings.timeout(options));
    }
    async _checkElementState(metadata, selector, state, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        const info = this._page.selectors._parseSelector(selector);
        const task = dom.elementStateTask(info, state);
        const result = await controller.run(async (progress) => {
            progress.log(`  checking "${state}" state of "${selector}"`);
            return this._scheduleRerunnableTask(progress, info.world, task);
        }, this._page._timeoutSettings.timeout(options));
        return dom.throwFatalDOMError(dom.throwRetargetableDOMError(result));
    }
    async isVisible(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            progress.log(`  checking visibility of "${selector}"`);
            const element = await this.$(selector);
            return element ? await element.isVisible() : false;
        }, this._page._timeoutSettings.timeout(options));
    }
    async isHidden(metadata, selector, options = {}) {
        return !(await this.isVisible(metadata, selector, options));
    }
    async isDisabled(metadata, selector, options = {}) {
        return this._checkElementState(metadata, selector, 'disabled', options);
    }
    async isEnabled(metadata, selector, options = {}) {
        return this._checkElementState(metadata, selector, 'enabled', options);
    }
    async isEditable(metadata, selector, options = {}) {
        return this._checkElementState(metadata, selector, 'editable', options);
    }
    async isChecked(metadata, selector, options = {}) {
        return this._checkElementState(metadata, selector, 'checked', options);
    }
    async hover(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._hover(progress, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async selectOption(metadata, selector, elements, values, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._selectOption(progress, elements, values, options));
        }, this._page._timeoutSettings.timeout(options));
    }
    async setInputFiles(metadata, selector, files, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._setInputFiles(progress, files, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async type(metadata, selector, text, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._type(progress, text, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async press(metadata, selector, key, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._press(progress, key, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async check(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._setChecked(progress, true, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async uncheck(metadata, selector, options = {}) {
        const controller = new progress_1.ProgressController(metadata, this);
        return controller.run(async (progress) => {
            return dom.assertDone(await this._retryWithProgressIfNotConnected(progress, selector, handle => handle._setChecked(progress, false, options)));
        }, this._page._timeoutSettings.timeout(options));
    }
    async _waitForFunctionExpression(metadata, expression, isFunction, arg, options, world = 'main') {
        const controller = new progress_1.ProgressController(metadata, this);
        if (typeof options.pollingInterval === 'number')
            utils_1.assert(options.pollingInterval > 0, 'Cannot poll with non-positive interval: ' + options.pollingInterval);
        expression = js.normalizeEvaluationExpression(expression, isFunction);
        const task = injectedScript => injectedScript.evaluateHandle((injectedScript, { expression, isFunction, polling, arg }) => {
            const predicate = (arg) => {
                let result = self.eval(expression);
                if (isFunction === true) {
                    result = result(arg);
                }
                else if (isFunction === false) {
                    result = result;
                }
                else {
                    // auto detect.
                    if (typeof result === 'function')
                        result = result(arg);
                }
                return result;
            };
            if (typeof polling !== 'number')
                return injectedScript.pollRaf((progress, continuePolling) => predicate(arg) || continuePolling);
            return injectedScript.pollInterval(polling, (progress, continuePolling) => predicate(arg) || continuePolling);
        }, { expression, isFunction, polling: options.pollingInterval, arg });
        return controller.run(progress => this._scheduleRerunnableHandleTask(progress, world, task), this._page._timeoutSettings.timeout(options));
    }
    async waitForFunctionValueInUtility(progress, pageFunction) {
        const expression = `() => {
      const result = (${pageFunction})();
      if (!result)
        return result;
      return JSON.stringify(result);
    }`;
        const handle = await this._waitForFunctionExpression(instrumentation_1.internalCallMetadata(), expression, true, undefined, { timeout: progress.timeUntilDeadline() }, 'utility');
        return JSON.parse(handle.rawValue());
    }
    async title() {
        const context = await this._utilityContext();
        return context.evaluate(() => document.title);
    }
    _onDetached() {
        this._stopNetworkIdleTimer();
        this._detached = true;
        this._detachedCallback();
        for (const data of this._contextData.values()) {
            for (const rerunnableTask of data.rerunnableTasks)
                rerunnableTask.terminate(new Error('waitForFunction failed: frame got detached.'));
        }
        if (this._parentFrame)
            this._parentFrame._childFrames.delete(this);
        this._parentFrame = null;
    }
    _scheduleRerunnableTask(progress, world, task) {
        const data = this._contextData.get(world);
        const rerunnableTask = new RerunnableTask(data, progress, task, true /* returnByValue */);
        if (this._detached)
            rerunnableTask.terminate(new Error('waitForFunction failed: frame got detached.'));
        if (data.context)
            rerunnableTask.rerun(data.context);
        return rerunnableTask.promise;
    }
    _scheduleRerunnableHandleTask(progress, world, task) {
        const data = this._contextData.get(world);
        const rerunnableTask = new RerunnableTask(data, progress, task, false /* returnByValue */);
        if (this._detached)
            rerunnableTask.terminate(new Error('waitForFunction failed: frame got detached.'));
        if (data.context)
            rerunnableTask.rerun(data.context);
        return rerunnableTask.promise;
    }
    _setContext(world, context) {
        const data = this._contextData.get(world);
        data.context = context;
        if (context) {
            data.contextResolveCallback.call(null, context);
            for (const rerunnableTask of data.rerunnableTasks)
                rerunnableTask.rerun(context);
        }
        else {
            data.contextPromise = new Promise(fulfill => {
                data.contextResolveCallback = fulfill;
            });
        }
    }
    _contextCreated(world, context) {
        const data = this._contextData.get(world);
        // In case of multiple sessions to the same target, there's a race between
        // connections so we might end up creating multiple isolated worlds.
        // We can use either.
        if (data.context)
            this._setContext(world, null);
        this._setContext(world, context);
    }
    _contextDestroyed(context) {
        for (const [world, data] of this._contextData) {
            if (data.context === context)
                this._setContext(world, null);
        }
    }
    _startNetworkIdleTimer() {
        utils_1.assert(!this._networkIdleTimer);
        // We should not start a timer and report networkidle in detached frames.
        // This happens at least in Firefox for child frames, where we may get requestFinished
        // after the frame was detached - probably a race in the Firefox itself.
        if (this._firedLifecycleEvents.has('networkidle') || this._detached)
            return;
        this._networkIdleTimer = setTimeout(() => this._onLifecycleEvent('networkidle'), 500);
    }
    _stopNetworkIdleTimer() {
        if (this._networkIdleTimer)
            clearTimeout(this._networkIdleTimer);
        this._networkIdleTimer = undefined;
    }
    async extendInjectedScript(world, source, arg) {
        const context = await this._context(world);
        const injectedScriptHandle = await context.injectedScript();
        return injectedScriptHandle.evaluateHandle((injectedScript, { source, arg }) => {
            return injectedScript.extend(source, arg);
        }, { source, arg });
    }
}
exports.Frame = Frame;
Frame.Events = {
    Navigation: 'navigation',
    AddLifecycle: 'addlifecycle',
    RemoveLifecycle: 'removelifecycle',
};
class RerunnableTask {
    constructor(data, progress, task, returnByValue) {
        this._resolve = () => { };
        this._reject = () => { };
        this._task = task;
        this._progress = progress;
        this._returnByValue = returnByValue;
        this._contextData = data;
        this._contextData.rerunnableTasks.add(this);
        this.promise = new Promise((resolve, reject) => {
            // The task is either resolved with a value, or rejected with a meaningful evaluation error.
            this._resolve = resolve;
            this._reject = reject;
        });
    }
    terminate(error) {
        this._reject(error);
    }
    async rerun(context) {
        try {
            const injectedScript = await context.injectedScript();
            const pollHandler = new dom.InjectedScriptPollHandler(this._progress, await this._task(injectedScript));
            const result = this._returnByValue ? await pollHandler.finish() : await pollHandler.finishHandle();
            this._contextData.rerunnableTasks.delete(this);
            this._resolve(result);
        }
        catch (e) {
            // We will try again in the new execution context.
            if (js.isContextDestroyedError(e))
                return;
            this._contextData.rerunnableTasks.delete(this);
            this._reject(e);
        }
    }
}
class SignalBarrier {
    constructor(progress) {
        this._protectCount = 0;
        this._promiseCallback = () => { };
        this._progress = progress;
        this._promise = new Promise(f => this._promiseCallback = f);
        this.retain();
    }
    waitFor() {
        this.release();
        return this._promise;
    }
    async addFrameNavigation(frame) {
        // Auto-wait top-level navigations only.
        if (frame.parentFrame())
            return;
        this.retain();
        const waiter = helper_1.helper.waitForEvent(null, frame, Frame.Events.Navigation, (e) => {
            if (!e.error && this._progress)
                this._progress.log(`  navigated to "${frame._url}"`);
            return true;
        });
        await Promise.race([
            frame._page._disconnectedPromise,
            frame._page._crashedPromise,
            frame._detachedPromise,
            waiter.promise,
        ]).catch(e => { });
        waiter.dispose();
        this.release();
    }
    retain() {
        ++this._protectCount;
    }
    release() {
        --this._protectCount;
        if (!this._protectCount)
            this._promiseCallback();
    }
}
function verifyLifecycle(name, waitUntil) {
    if (waitUntil === 'networkidle0')
        waitUntil = 'networkidle';
    if (!types.kLifecycleEvents.has(waitUntil))
        throw new Error(`${name}: expected one of (load|domcontentloaded|networkidle)`);
    return waitUntil;
}
//# sourceMappingURL=frames.js.map