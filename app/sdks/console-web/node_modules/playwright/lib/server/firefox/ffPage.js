"use strict";
/**
 * Copyright 2019 Google Inc. All rights reserved.
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
exports.FFPage = exports.UTILITY_WORLD_NAME = void 0;
const dialog = __importStar(require("../dialog"));
const dom = __importStar(require("../dom"));
const helper_1 = require("../helper");
const utils_1 = require("../../utils/utils");
const page_1 = require("../page");
const ffAccessibility_1 = require("./ffAccessibility");
const ffConnection_1 = require("./ffConnection");
const ffExecutionContext_1 = require("./ffExecutionContext");
const ffInput_1 = require("./ffInput");
const ffNetworkManager_1 = require("./ffNetworkManager");
const stackTrace_1 = require("../../utils/stackTrace");
const debugLogger_1 = require("../../utils/debugLogger");
exports.UTILITY_WORLD_NAME = '__playwright_utility_world__';
class FFPage {
    constructor(session, browserContext, opener) {
        this.cspErrorsAsynchronousForInlineScipts = true;
        this._pageCallback = () => { };
        this._initializedPage = null;
        this._initializationFailed = false;
        this._workers = new Map();
        this._session = session;
        this._opener = opener;
        this.rawKeyboard = new ffInput_1.RawKeyboardImpl(session);
        this.rawMouse = new ffInput_1.RawMouseImpl(session);
        this.rawTouchscreen = new ffInput_1.RawTouchscreenImpl(session);
        this._contextIdToContext = new Map();
        this._browserContext = browserContext;
        this._page = new page_1.Page(this, browserContext);
        this._networkManager = new ffNetworkManager_1.FFNetworkManager(session, this._page);
        this._page.on(page_1.Page.Events.FrameDetached, frame => this._removeContextsForFrame(frame));
        // TODO: remove Page.willOpenNewWindowAsynchronously from the protocol.
        this._eventListeners = [
            helper_1.helper.addEventListener(this._session, 'Page.eventFired', this._onEventFired.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.frameAttached', this._onFrameAttached.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.frameDetached', this._onFrameDetached.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.navigationAborted', this._onNavigationAborted.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.navigationCommitted', this._onNavigationCommitted.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.navigationStarted', this._onNavigationStarted.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.sameDocumentNavigation', this._onSameDocumentNavigation.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Runtime.executionContextCreated', this._onExecutionContextCreated.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Runtime.executionContextDestroyed', this._onExecutionContextDestroyed.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.linkClicked', event => this._onLinkClicked(event.phase)),
            helper_1.helper.addEventListener(this._session, 'Page.uncaughtError', this._onUncaughtError.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Runtime.console', this._onConsole.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.dialogOpened', this._onDialogOpened.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.bindingCalled', this._onBindingCalled.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.fileChooserOpened', this._onFileChooserOpened.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.workerCreated', this._onWorkerCreated.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.workerDestroyed', this._onWorkerDestroyed.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.dispatchMessageFromWorker', this._onDispatchMessageFromWorker.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.crashed', this._onCrashed.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.videoRecordingStarted', this._onVideoRecordingStarted.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.webSocketCreated', this._onWebSocketCreated.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.webSocketClosed', this._onWebSocketClosed.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.webSocketFrameReceived', this._onWebSocketFrameReceived.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.webSocketFrameSent', this._onWebSocketFrameSent.bind(this)),
            helper_1.helper.addEventListener(this._session, 'Page.screencastFrame', this._onScreencastFrame.bind(this)),
        ];
        this._pagePromise = new Promise(f => this._pageCallback = f);
        session.once(ffConnection_1.FFSessionEvents.Disconnected, () => {
            this._markAsError(new Error('Page closed'));
            this._page._didDisconnect();
        });
        this._session.once('Page.ready', async () => {
            await this._page.initOpener(this._opener);
            // Note: it is important to call |reportAsNew| before resolving pageOrError promise,
            // so that anyone who awaits pageOrError got a ready and reported page.
            this._initializedPage = this._page;
            this._page.reportAsNew();
            this._pageCallback(this._page);
        });
        // Ideally, we somehow ensure that utility world is created before Page.ready arrives, but currently it is racy.
        // Therefore, we can end up with an initialized page without utility world, although very unlikely.
        this._session.send('Page.addScriptToEvaluateOnNewDocument', { script: '', worldName: exports.UTILITY_WORLD_NAME }).catch(e => this._markAsError(e));
    }
    async _markAsError(error) {
        // Same error may be report twice: channer disconnected and session.send fails.
        if (this._initializationFailed)
            return;
        this._initializationFailed = true;
        if (!this._initializedPage) {
            await this._page.initOpener(this._opener);
            this._page.reportAsNew(error);
            this._pageCallback(error);
        }
    }
    async pageOrError() {
        return this._pagePromise;
    }
    _onWebSocketCreated(event) {
        this._page._frameManager.onWebSocketCreated(webSocketId(event.frameId, event.wsid), event.requestURL);
        this._page._frameManager.onWebSocketRequest(webSocketId(event.frameId, event.wsid));
    }
    _onWebSocketClosed(event) {
        if (event.error)
            this._page._frameManager.webSocketError(webSocketId(event.frameId, event.wsid), event.error);
        this._page._frameManager.webSocketClosed(webSocketId(event.frameId, event.wsid));
    }
    _onWebSocketFrameReceived(event) {
        this._page._frameManager.webSocketFrameReceived(webSocketId(event.frameId, event.wsid), event.opcode, event.data);
    }
    _onWebSocketFrameSent(event) {
        this._page._frameManager.onWebSocketFrameSent(webSocketId(event.frameId, event.wsid), event.opcode, event.data);
    }
    _onExecutionContextCreated(payload) {
        const { executionContextId, auxData } = payload;
        const frame = this._page._frameManager.frame(auxData.frameId);
        if (!frame)
            return;
        const delegate = new ffExecutionContext_1.FFExecutionContext(this._session, executionContextId);
        let worldName = null;
        if (auxData.name === exports.UTILITY_WORLD_NAME)
            worldName = 'utility';
        else if (!auxData.name)
            worldName = 'main';
        const context = new dom.FrameExecutionContext(delegate, frame, worldName);
        if (worldName)
            frame._contextCreated(worldName, context);
        this._contextIdToContext.set(executionContextId, context);
    }
    _onExecutionContextDestroyed(payload) {
        const { executionContextId } = payload;
        const context = this._contextIdToContext.get(executionContextId);
        if (!context)
            return;
        this._contextIdToContext.delete(executionContextId);
        context.frame._contextDestroyed(context);
    }
    _removeContextsForFrame(frame) {
        for (const [contextId, context] of this._contextIdToContext) {
            if (context.frame === frame)
                this._contextIdToContext.delete(contextId);
        }
    }
    _onLinkClicked(phase) {
        if (phase === 'before')
            this._page._frameManager.frameWillPotentiallyRequestNavigation();
        else
            this._page._frameManager.frameDidPotentiallyRequestNavigation();
    }
    _onNavigationStarted(params) {
        this._page._frameManager.frameRequestedNavigation(params.frameId, params.navigationId);
    }
    _onNavigationAborted(params) {
        this._page._frameManager.frameAbortedNavigation(params.frameId, params.errorText, params.navigationId);
    }
    _onNavigationCommitted(params) {
        for (const [workerId, worker] of this._workers) {
            if (worker.frameId === params.frameId)
                this._onWorkerDestroyed({ workerId });
        }
        this._page._frameManager.frameCommittedNewDocumentNavigation(params.frameId, params.url, params.name || '', params.navigationId || '', false);
    }
    _onSameDocumentNavigation(params) {
        this._page._frameManager.frameCommittedSameDocumentNavigation(params.frameId, params.url);
    }
    _onFrameAttached(params) {
        this._page._frameManager.frameAttached(params.frameId, params.parentFrameId);
    }
    _onFrameDetached(params) {
        this._page._frameManager.frameDetached(params.frameId);
    }
    _onEventFired(payload) {
        const { frameId, name } = payload;
        if (name === 'load')
            this._page._frameManager.frameLifecycleEvent(frameId, 'load');
        if (name === 'DOMContentLoaded')
            this._page._frameManager.frameLifecycleEvent(frameId, 'domcontentloaded');
    }
    _onUncaughtError(params) {
        const { name, message } = stackTrace_1.splitErrorMessage(params.message);
        const error = new Error(message);
        error.stack = params.stack;
        error.name = name;
        this._page.emit(page_1.Page.Events.PageError, error);
    }
    _onConsole(payload) {
        const { type, args, executionContextId, location } = payload;
        const context = this._contextIdToContext.get(executionContextId);
        this._page._addConsoleMessage(type, args.map(arg => context.createHandle(arg)), location);
    }
    _onDialogOpened(params) {
        this._page.emit(page_1.Page.Events.Dialog, new dialog.Dialog(this._page, params.type, params.message, async (accept, promptText) => {
            await this._session.sendMayFail('Page.handleDialog', { dialogId: params.dialogId, accept, promptText });
        }, params.defaultValue));
    }
    async _onBindingCalled(event) {
        const context = this._contextIdToContext.get(event.executionContextId);
        const pageOrError = await this.pageOrError();
        if (!(pageOrError instanceof Error))
            await this._page._onBindingCalled(event.payload, context);
    }
    async _onFileChooserOpened(payload) {
        const { executionContextId, element } = payload;
        const context = this._contextIdToContext.get(executionContextId);
        const handle = context.createHandle(element).asElement();
        await this._page._onFileChooserOpened(handle);
    }
    async _onWorkerCreated(event) {
        const workerId = event.workerId;
        const worker = new page_1.Worker(this._page, event.url);
        const workerSession = new ffConnection_1.FFSession(this._session._connection, 'worker', workerId, (message) => {
            this._session.send('Page.sendMessageToWorker', {
                frameId: event.frameId,
                workerId: workerId,
                message: JSON.stringify(message)
            }).catch(e => {
                workerSession.dispatchMessage({ id: message.id, method: '', params: {}, error: { message: e.message, data: undefined } });
            });
        });
        this._workers.set(workerId, { session: workerSession, frameId: event.frameId });
        this._page._addWorker(workerId, worker);
        workerSession.once('Runtime.executionContextCreated', event => {
            worker._createExecutionContext(new ffExecutionContext_1.FFExecutionContext(workerSession, event.executionContextId));
        });
        workerSession.on('Runtime.console', event => {
            const { type, args, location } = event;
            const context = worker._existingExecutionContext;
            this._page._addConsoleMessage(type, args.map(arg => context.createHandle(arg)), location);
        });
        // Note: we receive worker exceptions directly from the page.
    }
    _onWorkerDestroyed(event) {
        const workerId = event.workerId;
        const worker = this._workers.get(workerId);
        if (!worker)
            return;
        worker.session.dispose();
        this._workers.delete(workerId);
        this._page._removeWorker(workerId);
    }
    async _onDispatchMessageFromWorker(event) {
        const worker = this._workers.get(event.workerId);
        if (!worker)
            return;
        worker.session.dispatchMessage(JSON.parse(event.message));
    }
    async _onCrashed(event) {
        this._session.markAsCrashed();
        this._page._didCrash();
    }
    _onVideoRecordingStarted(event) {
        this._browserContext._browser._videoStarted(this._browserContext, event.screencastId, event.file, this.pageOrError());
    }
    async exposeBinding(binding) {
        const worldName = binding.world === 'utility' ? exports.UTILITY_WORLD_NAME : '';
        await this._session.send('Page.addBinding', { name: binding.name, script: binding.source, worldName });
    }
    didClose() {
        this._session.dispose();
        helper_1.helper.removeEventListeners(this._eventListeners);
        this._networkManager.dispose();
        this._page._didClose();
    }
    async navigateFrame(frame, url, referer) {
        const response = await this._session.send('Page.navigate', { url, referer, frameId: frame._id });
        return { newDocumentId: response.navigationId || undefined };
    }
    async updateExtraHTTPHeaders() {
        await this._session.send('Network.setExtraHTTPHeaders', { headers: this._page._state.extraHTTPHeaders || [] });
    }
    async setEmulatedSize(emulatedSize) {
        utils_1.assert(this._page._state.emulatedSize === emulatedSize);
        await this._session.send('Page.setViewportSize', {
            viewportSize: {
                width: emulatedSize.viewport.width,
                height: emulatedSize.viewport.height,
            },
        });
    }
    async bringToFront() {
        await this._session.send('Page.bringToFront', {});
    }
    async updateEmulateMedia() {
        const colorScheme = this._page._state.colorScheme === null ? undefined : this._page._state.colorScheme;
        const reducedMotion = this._page._state.reducedMotion === null ? undefined : this._page._state.reducedMotion;
        await this._session.send('Page.setEmulatedMedia', {
            // Empty string means reset.
            type: this._page._state.mediaType === null ? '' : this._page._state.mediaType,
            colorScheme,
            reducedMotion,
        });
    }
    async updateRequestInterception() {
        await this._networkManager.setRequestInterception(this._page._needsRequestInterception());
    }
    async setFileChooserIntercepted(enabled) {
        await this._session.send('Page.setInterceptFileChooserDialog', { enabled }).catch(e => { }); // target can be closed.
    }
    async reload() {
        await this._session.send('Page.reload', { frameId: this._page.mainFrame()._id });
    }
    async goBack() {
        const { success } = await this._session.send('Page.goBack', { frameId: this._page.mainFrame()._id });
        return success;
    }
    async goForward() {
        const { success } = await this._session.send('Page.goForward', { frameId: this._page.mainFrame()._id });
        return success;
    }
    async evaluateOnNewDocument(source) {
        await this._session.send('Page.addScriptToEvaluateOnNewDocument', { script: source });
    }
    async closePage(runBeforeUnload) {
        await this._session.send('Page.close', { runBeforeUnload });
    }
    canScreenshotOutsideViewport() {
        return true;
    }
    async setBackgroundColor(color) {
        if (color)
            throw new Error('Not implemented');
    }
    async takeScreenshot(progress, format, documentRect, viewportRect, quality) {
        if (!documentRect) {
            const scrollOffset = await this._page.mainFrame().waitForFunctionValueInUtility(progress, () => ({ x: window.scrollX, y: window.scrollY }));
            documentRect = {
                x: viewportRect.x + scrollOffset.x,
                y: viewportRect.y + scrollOffset.y,
                width: viewportRect.width,
                height: viewportRect.height,
            };
        }
        // TODO: remove fullPage option from Page.screenshot.
        // TODO: remove Page.getBoundingBox method.
        progress.throwIfAborted();
        const { data } = await this._session.send('Page.screenshot', {
            mimeType: ('image/' + format),
            clip: documentRect,
        });
        return Buffer.from(data, 'base64');
    }
    async resetViewport() {
        utils_1.assert(false, 'Should not be called');
    }
    async getContentFrame(handle) {
        const { contentFrameId } = await this._session.send('Page.describeNode', {
            frameId: handle._context.frame._id,
            objectId: handle._objectId,
        });
        if (!contentFrameId)
            return null;
        return this._page._frameManager.frame(contentFrameId);
    }
    async getOwnerFrame(handle) {
        const { ownerFrameId } = await this._session.send('Page.describeNode', {
            frameId: handle._context.frame._id,
            objectId: handle._objectId
        });
        return ownerFrameId || null;
    }
    isElementHandle(remoteObject) {
        return remoteObject.subtype === 'node';
    }
    async getBoundingBox(handle) {
        const quads = await this.getContentQuads(handle);
        if (!quads || !quads.length)
            return null;
        let minX = Infinity;
        let maxX = -Infinity;
        let minY = Infinity;
        let maxY = -Infinity;
        for (const quad of quads) {
            for (const point of quad) {
                minX = Math.min(minX, point.x);
                maxX = Math.max(maxX, point.x);
                minY = Math.min(minY, point.y);
                maxY = Math.max(maxY, point.y);
            }
        }
        return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
    }
    async scrollRectIntoViewIfNeeded(handle, rect) {
        return await this._session.send('Page.scrollIntoViewIfNeeded', {
            frameId: handle._context.frame._id,
            objectId: handle._objectId,
            rect,
        }).then(() => 'done').catch(e => {
            if (e instanceof Error && e.message.includes('Node is detached from document'))
                return 'error:notconnected';
            if (e instanceof Error && e.message.includes('Node does not have a layout object'))
                return 'error:notvisible';
            throw e;
        });
    }
    async setScreencastOptions(options) {
        if (options) {
            const { screencastId } = await this._session.send('Page.startScreencast', options);
            this._screencastId = screencastId;
        }
        else {
            await this._session.send('Page.stopScreencast');
        }
    }
    _onScreencastFrame(event) {
        if (!this._screencastId)
            return;
        this._session.send('Page.screencastFrameAck', { screencastId: this._screencastId }).catch(e => debugLogger_1.debugLogger.log('error', e));
        const buffer = Buffer.from(event.data, 'base64');
        this._page.emit(page_1.Page.Events.ScreencastFrame, {
            buffer,
            width: event.deviceWidth,
            height: event.deviceHeight,
        });
    }
    rafCountForStablePosition() {
        return 1;
    }
    async getContentQuads(handle) {
        const result = await this._session.sendMayFail('Page.getContentQuads', {
            frameId: handle._context.frame._id,
            objectId: handle._objectId,
        });
        if (!result)
            return null;
        return result.quads.map(quad => [quad.p1, quad.p2, quad.p3, quad.p4]);
    }
    async setInputFiles(handle, files) {
        await handle.evaluateInUtility(([injected, node, files]) => injected.setInputFiles(node, files), files);
    }
    async adoptElementHandle(handle, to) {
        const result = await this._session.send('Page.adoptNode', {
            frameId: handle._context.frame._id,
            objectId: handle._objectId,
            executionContextId: to._delegate._executionContextId
        });
        if (!result.remoteObject)
            throw new Error(dom.kUnableToAdoptErrorMessage);
        return to.createHandle(result.remoteObject);
    }
    async getAccessibilityTree(needle) {
        return ffAccessibility_1.getAccessibilityTree(this._session, needle);
    }
    async inputActionEpilogue() {
    }
    async getFrameElement(frame) {
        const parent = frame.parentFrame();
        if (!parent)
            throw new Error('Frame has been detached.');
        const handles = await this._page.selectors._queryAll(parent, 'frame,iframe', undefined);
        const items = await Promise.all(handles.map(async (handle) => {
            const frame = await handle.contentFrame().catch(e => null);
            return { handle, frame };
        }));
        const result = items.find(item => item.frame === frame);
        items.map(item => item === result ? Promise.resolve() : item.handle.dispose());
        if (!result)
            throw new Error('Frame has been detached.');
        return result.handle;
    }
}
exports.FFPage = FFPage;
function webSocketId(frameId, wsid) {
    return `${frameId}---${wsid}`;
}
//# sourceMappingURL=ffPage.js.map