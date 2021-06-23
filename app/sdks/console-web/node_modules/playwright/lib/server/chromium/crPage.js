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
exports.CRPage = void 0;
const dom = __importStar(require("../dom"));
const helper_1 = require("../helper");
const network = __importStar(require("../network"));
const crConnection_1 = require("./crConnection");
const crExecutionContext_1 = require("./crExecutionContext");
const crNetworkManager_1 = require("./crNetworkManager");
const page_1 = require("../page");
const crProtocolHelper_1 = require("./crProtocolHelper");
const dialog = __importStar(require("../dialog"));
const path_1 = __importDefault(require("path"));
const crInput_1 = require("./crInput");
const crAccessibility_1 = require("./crAccessibility");
const crCoverage_1 = require("./crCoverage");
const crPdf_1 = require("./crPdf");
const crBrowser_1 = require("./crBrowser");
const console_1 = require("../console");
const stackTrace_1 = require("../../utils/stackTrace");
const utils_1 = require("../../utils/utils");
const videoRecorder_1 = require("./videoRecorder");
const crDragDrop_1 = require("./crDragDrop");
const UTILITY_WORLD_NAME = '__playwright_utility_world__';
class CRPage {
    constructor(client, targetId, browserContext, opener, hasUIWindow, isBackgroundPage) {
        this._sessions = new Map();
        this._initializedPage = null;
        // Holds window features for the next popup being opened via window.open,
        // until the popup target arrives. This could be racy if two oopifs
        // simultaneously call window.open with window features: the order
        // of their Page.windowOpen events is not guaranteed to match the order
        // of new popup targets.
        this._nextWindowOpenPopupFeatures = [];
        this._targetId = targetId;
        this._opener = opener;
        this._isBackgroundPage = isBackgroundPage;
        const dragManager = new crDragDrop_1.DragManager(this);
        this.rawKeyboard = new crInput_1.RawKeyboardImpl(client, browserContext._browser._isMac, dragManager);
        this.rawMouse = new crInput_1.RawMouseImpl(this, client, dragManager);
        this.rawTouchscreen = new crInput_1.RawTouchscreenImpl(client);
        this._pdf = new crPdf_1.CRPDF(client);
        this._coverage = new crCoverage_1.CRCoverage(client);
        this._browserContext = browserContext;
        this._page = new page_1.Page(this, browserContext);
        this._mainFrameSession = new FrameSession(this, client, targetId, null);
        this._sessions.set(targetId, this._mainFrameSession);
        client.once(crConnection_1.CRSessionEvents.Disconnected, () => this._page._didDisconnect());
        if (opener && !browserContext._options.noDefaultViewport) {
            const features = opener._nextWindowOpenPopupFeatures.shift() || [];
            const viewportSize = helper_1.helper.getViewportSizeFromWindowFeatures(features);
            if (viewportSize)
                this._page._state.emulatedSize = { viewport: viewportSize, screen: viewportSize };
        }
        // Note: it is important to call |reportAsNew| before resolving pageOrError promise,
        // so that anyone who awaits pageOrError got a ready and reported page.
        this._pagePromise = this._mainFrameSession._initialize(hasUIWindow).then(async (r) => {
            await this._page.initOpener(this._opener);
            return r;
        }).catch(async (e) => {
            await this._page.initOpener(this._opener);
            throw e;
        }).then(() => {
            this._initializedPage = this._page;
            this._reportAsNew();
            return this._page;
        }).catch(e => {
            this._reportAsNew(e);
            return e;
        });
    }
    static mainFrameSession(page) {
        const crPage = page._delegate;
        return crPage._mainFrameSession;
    }
    _reportAsNew(error) {
        if (this._isBackgroundPage) {
            if (!error)
                this._browserContext.emit(crBrowser_1.CRBrowserContext.CREvents.BackgroundPage, this._page);
        }
        else {
            this._page.reportAsNew(error);
        }
    }
    async _forAllFrameSessions(cb) {
        const frameSessions = Array.from(this._sessions.values());
        await Promise.all(frameSessions.map(frameSession => {
            if (frameSession._isMainFrame())
                return cb(frameSession);
            return cb(frameSession).catch(e => {
                // Broadcasting a message to the closed iframe shoule be a noop.
                if (e.message && (e.message.includes('Target closed.') || e.message.includes('Session closed.')))
                    return;
                throw e;
            });
        }));
    }
    _sessionForFrame(frame) {
        // Frame id equals target id.
        while (!this._sessions.has(frame._id)) {
            const parent = frame.parentFrame();
            if (!parent)
                throw new Error(`Frame has been detached.`);
            frame = parent;
        }
        return this._sessions.get(frame._id);
    }
    _sessionForHandle(handle) {
        const frame = handle._context.frame;
        return this._sessionForFrame(frame);
    }
    willBeginDownload() {
        this._mainFrameSession._willBeginDownload();
    }
    async pageOrError() {
        return this._pagePromise;
    }
    didClose() {
        for (const session of this._sessions.values())
            session.dispose();
        this._page._didClose();
    }
    async navigateFrame(frame, url, referrer) {
        return this._sessionForFrame(frame)._navigate(frame, url, referrer);
    }
    async exposeBinding(binding) {
        await this._forAllFrameSessions(frame => frame._initBinding(binding));
        await Promise.all(this._page.frames().map(frame => frame.evaluateExpression(binding.source, false, {}, binding.world).catch(e => { })));
    }
    async updateExtraHTTPHeaders() {
        await this._forAllFrameSessions(frame => frame._updateExtraHTTPHeaders(false));
    }
    async updateGeolocation() {
        await this._forAllFrameSessions(frame => frame._updateGeolocation(false));
    }
    async updateOffline() {
        await this._forAllFrameSessions(frame => frame._updateOffline(false));
    }
    async updateHttpCredentials() {
        await this._forAllFrameSessions(frame => frame._updateHttpCredentials(false));
    }
    async setEmulatedSize(emulatedSize) {
        utils_1.assert(this._page._state.emulatedSize === emulatedSize);
        await this._mainFrameSession._updateViewport();
    }
    async bringToFront() {
        await this._mainFrameSession._client.send('Page.bringToFront');
    }
    async updateEmulateMedia() {
        await this._forAllFrameSessions(frame => frame._updateEmulateMedia(false));
    }
    async updateRequestInterception() {
        await this._forAllFrameSessions(frame => frame._updateRequestInterception(false));
    }
    async setFileChooserIntercepted(enabled) {
        await this._forAllFrameSessions(frame => frame._setFileChooserIntercepted(enabled));
    }
    async reload() {
        await this._mainFrameSession._client.send('Page.reload');
    }
    async _go(delta) {
        const history = await this._mainFrameSession._client.send('Page.getNavigationHistory');
        const entry = history.entries[history.currentIndex + delta];
        if (!entry)
            return false;
        await this._mainFrameSession._client.send('Page.navigateToHistoryEntry', { entryId: entry.id });
        return true;
    }
    goBack() {
        return this._go(-1);
    }
    goForward() {
        return this._go(+1);
    }
    async evaluateOnNewDocument(source, world = 'main') {
        await this._forAllFrameSessions(frame => frame._evaluateOnNewDocument(source, world));
    }
    async closePage(runBeforeUnload) {
        if (runBeforeUnload)
            await this._mainFrameSession._client.send('Page.close');
        else
            await this._browserContext._browser._closePage(this);
    }
    canScreenshotOutsideViewport() {
        return false;
    }
    async setBackgroundColor(color) {
        await this._mainFrameSession._client.send('Emulation.setDefaultBackgroundColorOverride', { color });
    }
    async takeScreenshot(progress, format, documentRect, viewportRect, quality) {
        const { visualViewport } = await this._mainFrameSession._client.send('Page.getLayoutMetrics');
        if (!documentRect) {
            documentRect = {
                x: visualViewport.pageX + viewportRect.x,
                y: visualViewport.pageY + viewportRect.y,
                ...helper_1.helper.enclosingIntSize({
                    width: viewportRect.width / visualViewport.scale,
                    height: viewportRect.height / visualViewport.scale,
                })
            };
        }
        // When taking screenshots with documentRect (based on the page content, not viewport),
        // ignore current page scale.
        const clip = { ...documentRect, scale: viewportRect ? visualViewport.scale : 1 };
        progress.throwIfAborted();
        const result = await this._mainFrameSession._client.send('Page.captureScreenshot', { format, quality, clip });
        return Buffer.from(result.data, 'base64');
    }
    async resetViewport() {
        await this._mainFrameSession._client.send('Emulation.setDeviceMetricsOverride', { mobile: false, width: 0, height: 0, deviceScaleFactor: 0 });
    }
    async getContentFrame(handle) {
        return this._sessionForHandle(handle)._getContentFrame(handle);
    }
    async getOwnerFrame(handle) {
        return this._sessionForHandle(handle)._getOwnerFrame(handle);
    }
    isElementHandle(remoteObject) {
        return remoteObject.subtype === 'node';
    }
    async getBoundingBox(handle) {
        return this._sessionForHandle(handle)._getBoundingBox(handle);
    }
    async scrollRectIntoViewIfNeeded(handle, rect) {
        return this._sessionForHandle(handle)._scrollRectIntoViewIfNeeded(handle, rect);
    }
    async setScreencastOptions(options) {
        if (options) {
            await this._mainFrameSession._startScreencast(this, {
                format: 'jpeg',
                quality: options.quality,
                maxWidth: options.width,
                maxHeight: options.height
            });
        }
        else {
            await this._mainFrameSession._stopScreencast(this);
        }
    }
    rafCountForStablePosition() {
        return 1;
    }
    async getContentQuads(handle) {
        return this._sessionForHandle(handle)._getContentQuads(handle);
    }
    async setInputFiles(handle, files) {
        await handle.evaluateInUtility(([injected, node, files]) => injected.setInputFiles(node, files), files);
    }
    async adoptElementHandle(handle, to) {
        return this._sessionForHandle(handle)._adoptElementHandle(handle, to);
    }
    async getAccessibilityTree(needle) {
        return crAccessibility_1.getAccessibilityTree(this._mainFrameSession._client, needle);
    }
    async inputActionEpilogue() {
        await this._mainFrameSession._client.send('Page.enable').catch(e => { });
    }
    async pdf(options) {
        return this._pdf.generate(options);
    }
    coverage() {
        return this._coverage;
    }
    async getFrameElement(frame) {
        let parent = frame.parentFrame();
        if (!parent)
            throw new Error('Frame has been detached.');
        const parentSession = this._sessionForFrame(parent);
        const { backendNodeId } = await parentSession._client.send('DOM.getFrameOwner', { frameId: frame._id }).catch(e => {
            if (e instanceof Error && e.message.includes('Frame with the given id was not found.'))
                stackTrace_1.rewriteErrorMessage(e, 'Frame has been detached.');
            throw e;
        });
        parent = frame.parentFrame();
        if (!parent)
            throw new Error('Frame has been detached.');
        return parentSession._adoptBackendNodeId(backendNodeId, await parent._mainContext());
    }
}
exports.CRPage = CRPage;
class FrameSession {
    constructor(crPage, client, targetId, parentSession) {
        this._contextIdToContext = new Map();
        this._eventListeners = [];
        this._firstNonInitialNavigationCommittedFulfill = () => { };
        this._firstNonInitialNavigationCommittedReject = (e) => { };
        // Marks the oopif session that remote -> local transition has happened in the parent.
        // See Target.detachedFromTarget handler for details.
        this._swappedIn = false;
        this._videoRecorder = null;
        this._screencastId = null;
        this._screencastClients = new Set();
        this._client = client;
        this._crPage = crPage;
        this._page = crPage._page;
        this._targetId = targetId;
        this._networkManager = new crNetworkManager_1.CRNetworkManager(client, this._page, parentSession ? parentSession._networkManager : null);
        this._firstNonInitialNavigationCommittedPromise = new Promise((f, r) => {
            this._firstNonInitialNavigationCommittedFulfill = f;
            this._firstNonInitialNavigationCommittedReject = r;
        });
        client.once(crConnection_1.CRSessionEvents.Disconnected, () => {
            this._firstNonInitialNavigationCommittedReject(new Error('Page closed'));
        });
    }
    _isMainFrame() {
        return this._targetId === this._crPage._targetId;
    }
    _addRendererListeners() {
        this._eventListeners.push(...[
            helper_1.helper.addEventListener(this._client, 'Log.entryAdded', event => this._onLogEntryAdded(event)),
            helper_1.helper.addEventListener(this._client, 'Page.fileChooserOpened', event => this._onFileChooserOpened(event)),
            helper_1.helper.addEventListener(this._client, 'Page.frameAttached', event => this._onFrameAttached(event.frameId, event.parentFrameId)),
            helper_1.helper.addEventListener(this._client, 'Page.frameDetached', event => this._onFrameDetached(event.frameId, event.reason)),
            helper_1.helper.addEventListener(this._client, 'Page.frameNavigated', event => this._onFrameNavigated(event.frame, false)),
            helper_1.helper.addEventListener(this._client, 'Page.frameRequestedNavigation', event => this._onFrameRequestedNavigation(event)),
            helper_1.helper.addEventListener(this._client, 'Page.frameStoppedLoading', event => this._onFrameStoppedLoading(event.frameId)),
            helper_1.helper.addEventListener(this._client, 'Page.javascriptDialogOpening', event => this._onDialog(event)),
            helper_1.helper.addEventListener(this._client, 'Page.navigatedWithinDocument', event => this._onFrameNavigatedWithinDocument(event.frameId, event.url)),
            helper_1.helper.addEventListener(this._client, 'Runtime.bindingCalled', event => this._onBindingCalled(event)),
            helper_1.helper.addEventListener(this._client, 'Runtime.consoleAPICalled', event => this._onConsoleAPI(event)),
            helper_1.helper.addEventListener(this._client, 'Runtime.exceptionThrown', exception => this._handleException(exception.exceptionDetails)),
            helper_1.helper.addEventListener(this._client, 'Runtime.executionContextCreated', event => this._onExecutionContextCreated(event.context)),
            helper_1.helper.addEventListener(this._client, 'Runtime.executionContextDestroyed', event => this._onExecutionContextDestroyed(event.executionContextId)),
            helper_1.helper.addEventListener(this._client, 'Runtime.executionContextsCleared', event => this._onExecutionContextsCleared()),
            helper_1.helper.addEventListener(this._client, 'Target.attachedToTarget', event => this._onAttachedToTarget(event)),
            helper_1.helper.addEventListener(this._client, 'Target.detachedFromTarget', event => this._onDetachedFromTarget(event)),
        ]);
    }
    _addBrowserListeners() {
        this._eventListeners.push(...[
            helper_1.helper.addEventListener(this._client, 'Inspector.targetCrashed', event => this._onTargetCrashed()),
            helper_1.helper.addEventListener(this._client, 'Page.screencastFrame', event => this._onScreencastFrame(event)),
            helper_1.helper.addEventListener(this._client, 'Page.windowOpen', event => this._onWindowOpen(event)),
        ]);
    }
    async _initialize(hasUIWindow) {
        if (hasUIWindow &&
            !this._crPage._browserContext._browser.isClank() &&
            !this._crPage._browserContext._options.noDefaultViewport) {
            const { windowId } = await this._client.send('Browser.getWindowForTarget');
            this._windowId = windowId;
        }
        let screencastOptions;
        if (this._isMainFrame() && this._crPage._browserContext._options.recordVideo && hasUIWindow) {
            const screencastId = utils_1.createGuid();
            const outputFile = path_1.default.join(this._crPage._browserContext._options.recordVideo.dir, screencastId + '.webm');
            screencastOptions = {
                // validateBrowserContextOptions ensures correct video size.
                ...this._crPage._browserContext._options.recordVideo.size,
                outputFile,
            };
            await this._crPage._browserContext._ensureVideosPath();
            // Note: it is important to start video recorder before sending Page.startScreencast,
            // and it is equally important to send Page.startScreencast before sending Runtime.runIfWaitingForDebugger.
            await this._createVideoRecorder(screencastId, screencastOptions);
            this._crPage.pageOrError().then(p => {
                if (p instanceof Error)
                    this._stopVideoRecording().catch(() => { });
            });
        }
        let lifecycleEventsEnabled;
        if (!this._isMainFrame())
            this._addRendererListeners();
        this._addBrowserListeners();
        const promises = [
            this._client.send('Page.enable'),
            this._client.send('Page.getFrameTree').then(({ frameTree }) => {
                if (this._isMainFrame()) {
                    this._handleFrameTree(frameTree);
                    this._addRendererListeners();
                }
                const localFrames = this._isMainFrame() ? this._page.frames() : [this._page._frameManager.frame(this._targetId)];
                for (const frame of localFrames) {
                    // Note: frames might be removed before we send these.
                    this._client._sendMayFail('Page.createIsolatedWorld', {
                        frameId: frame._id,
                        grantUniveralAccess: true,
                        worldName: UTILITY_WORLD_NAME,
                    });
                    for (const binding of this._crPage._browserContext._pageBindings.values())
                        frame.evaluateExpression(binding.source, false, undefined, binding.world).catch(e => { });
                    for (const source of this._crPage._browserContext._evaluateOnNewDocumentSources)
                        frame.evaluateExpression(source, false, undefined, 'main').catch(e => { });
                }
                const isInitialEmptyPage = this._isMainFrame() && this._page.mainFrame().url() === ':';
                if (isInitialEmptyPage) {
                    // Ignore lifecycle events for the initial empty page. It is never the final page
                    // hence we are going to get more lifecycle updates after the actual navigation has
                    // started (even if the target url is about:blank).
                    lifecycleEventsEnabled.then(() => {
                        this._eventListeners.push(helper_1.helper.addEventListener(this._client, 'Page.lifecycleEvent', event => this._onLifecycleEvent(event)));
                    });
                }
                else {
                    this._firstNonInitialNavigationCommittedFulfill();
                    this._eventListeners.push(helper_1.helper.addEventListener(this._client, 'Page.lifecycleEvent', event => this._onLifecycleEvent(event)));
                }
            }),
            this._client.send('Log.enable', {}),
            lifecycleEventsEnabled = this._client.send('Page.setLifecycleEventsEnabled', { enabled: true }),
            this._client.send('Runtime.enable', {}),
            this._client.send('Page.addScriptToEvaluateOnNewDocument', {
                source: '',
                worldName: UTILITY_WORLD_NAME,
            }),
            this._networkManager.initialize(),
            this._client.send('Target.setAutoAttach', { autoAttach: true, waitForDebuggerOnStart: true, flatten: true }),
        ];
        if (this._isMainFrame())
            promises.push(this._client.send('Emulation.setFocusEmulationEnabled', { enabled: true }));
        const options = this._crPage._browserContext._options;
        if (options.bypassCSP)
            promises.push(this._client.send('Page.setBypassCSP', { enabled: true }));
        if (options.ignoreHTTPSErrors)
            promises.push(this._client.send('Security.setIgnoreCertificateErrors', { ignore: true }));
        if (this._isMainFrame())
            promises.push(this._updateViewport());
        if (options.hasTouch)
            promises.push(this._client.send('Emulation.setTouchEmulationEnabled', { enabled: true }));
        if (options.javaScriptEnabled === false)
            promises.push(this._client.send('Emulation.setScriptExecutionDisabled', { value: true }));
        if (options.userAgent || options.locale)
            promises.push(this._client.send('Emulation.setUserAgentOverride', { userAgent: options.userAgent || '', acceptLanguage: options.locale }));
        if (options.locale)
            promises.push(emulateLocale(this._client, options.locale));
        if (options.timezoneId)
            promises.push(emulateTimezone(this._client, options.timezoneId));
        promises.push(this._updateGeolocation(true));
        promises.push(this._updateExtraHTTPHeaders(true));
        promises.push(this._updateRequestInterception(true));
        promises.push(this._updateOffline(true));
        promises.push(this._updateHttpCredentials(true));
        promises.push(this._updateEmulateMedia(true));
        for (const binding of this._crPage._page.allBindings())
            promises.push(this._initBinding(binding));
        for (const source of this._crPage._browserContext._evaluateOnNewDocumentSources)
            promises.push(this._evaluateOnNewDocument(source, 'main'));
        for (const source of this._crPage._page._evaluateOnNewDocumentSources)
            promises.push(this._evaluateOnNewDocument(source, 'main'));
        if (screencastOptions)
            promises.push(this._startVideoRecording(screencastOptions));
        promises.push(this._client.send('Runtime.runIfWaitingForDebugger'));
        promises.push(this._firstNonInitialNavigationCommittedPromise);
        await Promise.all(promises);
    }
    dispose() {
        helper_1.helper.removeEventListeners(this._eventListeners);
        this._networkManager.dispose();
        this._crPage._sessions.delete(this._targetId);
    }
    async _navigate(frame, url, referrer) {
        const response = await this._client.send('Page.navigate', { url, referrer, frameId: frame._id });
        if (response.errorText)
            throw new Error(`${response.errorText} at ${url}`);
        return { newDocumentId: response.loaderId };
    }
    _onLifecycleEvent(event) {
        if (event.name === 'load')
            this._page._frameManager.frameLifecycleEvent(event.frameId, 'load');
        else if (event.name === 'DOMContentLoaded')
            this._page._frameManager.frameLifecycleEvent(event.frameId, 'domcontentloaded');
    }
    _onFrameStoppedLoading(frameId) {
        this._page._frameManager.frameStoppedLoading(frameId);
    }
    _handleFrameTree(frameTree) {
        this._onFrameAttached(frameTree.frame.id, frameTree.frame.parentId || null);
        this._onFrameNavigated(frameTree.frame, true);
        if (!frameTree.childFrames)
            return;
        for (const child of frameTree.childFrames)
            this._handleFrameTree(child);
    }
    _onFrameAttached(frameId, parentFrameId) {
        const frameSession = this._crPage._sessions.get(frameId);
        if (frameSession && frameId !== this._targetId) {
            // This is a remote -> local frame transition.
            frameSession._swappedIn = true;
            const frame = this._page._frameManager.frame(frameId);
            // Frame or even a whole subtree may be already gone, because some ancestor did navigate.
            if (frame)
                this._page._frameManager.removeChildFramesRecursively(frame);
            return;
        }
        if (parentFrameId && !this._page._frameManager.frame(parentFrameId)) {
            // Parent frame may be gone already because some ancestor frame navigated and
            // destroyed the whole subtree of some oopif, while oopif's process is still sending us events.
            // Be careful to not confuse this with "main frame navigated cross-process" scenario
            // where parentFrameId is null.
            return;
        }
        this._page._frameManager.frameAttached(frameId, parentFrameId);
    }
    _onFrameNavigated(framePayload, initial) {
        if (!this._page._frameManager.frame(framePayload.id))
            return; // Subtree may be already gone because some ancestor navigation destroyed the oopif.
        this._page._frameManager.frameCommittedNewDocumentNavigation(framePayload.id, framePayload.url + (framePayload.urlFragment || ''), framePayload.name || '', framePayload.loaderId, initial);
        if (!initial)
            this._firstNonInitialNavigationCommittedFulfill();
    }
    _onFrameRequestedNavigation(payload) {
        if (payload.disposition === 'currentTab')
            this._page._frameManager.frameRequestedNavigation(payload.frameId);
    }
    _onFrameNavigatedWithinDocument(frameId, url) {
        this._page._frameManager.frameCommittedSameDocumentNavigation(frameId, url);
    }
    _onFrameDetached(frameId, reason) {
        if (this._crPage._sessions.has(frameId)) {
            // This is a local -> remote frame transtion, where
            // Page.frameDetached arrives after Target.attachedToTarget.
            // We've already handled the new target and frame reattach - nothing to do here.
            return;
        }
        if (reason === 'swap') {
            // This is a local -> remote frame transtion, where
            // Page.frameDetached arrives before Target.attachedToTarget.
            // We should keep the frame in the tree, and it will be used for the new target.
            const frame = this._page._frameManager.frame(frameId);
            if (frame)
                this._page._frameManager.removeChildFramesRecursively(frame);
            return;
        }
        // Just a regular frame detach.
        this._page._frameManager.frameDetached(frameId);
    }
    _onExecutionContextCreated(contextPayload) {
        const frame = contextPayload.auxData ? this._page._frameManager.frame(contextPayload.auxData.frameId) : null;
        if (!frame)
            return;
        const delegate = new crExecutionContext_1.CRExecutionContext(this._client, contextPayload);
        let worldName = null;
        if (contextPayload.auxData && !!contextPayload.auxData.isDefault)
            worldName = 'main';
        else if (contextPayload.name === UTILITY_WORLD_NAME)
            worldName = 'utility';
        const context = new dom.FrameExecutionContext(delegate, frame, worldName);
        if (worldName)
            frame._contextCreated(worldName, context);
        this._contextIdToContext.set(contextPayload.id, context);
    }
    _onExecutionContextDestroyed(executionContextId) {
        const context = this._contextIdToContext.get(executionContextId);
        if (!context)
            return;
        this._contextIdToContext.delete(executionContextId);
        context.frame._contextDestroyed(context);
    }
    _onExecutionContextsCleared() {
        for (const contextId of Array.from(this._contextIdToContext.keys()))
            this._onExecutionContextDestroyed(contextId);
    }
    _onAttachedToTarget(event) {
        const session = crConnection_1.CRConnection.fromSession(this._client).session(event.sessionId);
        if (event.targetInfo.type === 'iframe') {
            // Frame id equals target id.
            const targetId = event.targetInfo.targetId;
            const frame = this._page._frameManager.frame(targetId);
            if (!frame)
                return; // Subtree may be already gone due to renderer/browser race.
            this._page._frameManager.removeChildFramesRecursively(frame);
            const frameSession = new FrameSession(this._crPage, session, targetId, this);
            this._crPage._sessions.set(targetId, frameSession);
            frameSession._initialize(false).catch(e => e);
            return;
        }
        if (event.targetInfo.type !== 'worker') {
            // Ideally, detaching should resume any target, but there is a bug in the backend.
            session._sendMayFail('Runtime.runIfWaitingForDebugger').then(() => {
                this._client._sendMayFail('Target.detachFromTarget', { sessionId: event.sessionId });
            });
            return;
        }
        const url = event.targetInfo.url;
        const worker = new page_1.Worker(this._page, url);
        this._page._addWorker(event.sessionId, worker);
        session.once('Runtime.executionContextCreated', async (event) => {
            worker._createExecutionContext(new crExecutionContext_1.CRExecutionContext(session, event.context));
        });
        // This might fail if the target is closed before we initialize.
        session._sendMayFail('Runtime.enable');
        session._sendMayFail('Network.enable');
        session._sendMayFail('Runtime.runIfWaitingForDebugger');
        session.on('Runtime.consoleAPICalled', event => {
            const args = event.args.map(o => worker._existingExecutionContext.createHandle(o));
            this._page._addConsoleMessage(event.type, args, crProtocolHelper_1.toConsoleMessageLocation(event.stackTrace));
        });
        session.on('Runtime.exceptionThrown', exception => this._page.emit(page_1.Page.Events.PageError, crProtocolHelper_1.exceptionToError(exception.exceptionDetails)));
        // TODO: attribute workers to the right frame.
        this._networkManager.instrumentNetworkEvents(session, this._page._frameManager.frame(this._targetId));
    }
    _onDetachedFromTarget(event) {
        // This might be a worker...
        this._page._removeWorker(event.sessionId);
        // ... or an oopif.
        const childFrameSession = this._crPage._sessions.get(event.targetId);
        if (!childFrameSession)
            return;
        // Usually, we get frameAttached in this session first and mark child as swappedIn.
        if (childFrameSession._swappedIn) {
            childFrameSession.dispose();
            return;
        }
        // However, sometimes we get detachedFromTarget before frameAttached.
        // In this case we don't know wheter this is a remote frame detach,
        // or just a remote -> local transition. In the latter case, frameAttached
        // is already inflight, so let's make a safe roundtrip to ensure it arrives.
        this._client.send('Page.enable').catch(e => null).then(() => {
            // Child was not swapped in - that means frameAttached did not happen and
            // this is remote detach rather than remote -> local swap.
            if (!childFrameSession._swappedIn)
                this._page._frameManager.frameDetached(event.targetId);
            childFrameSession.dispose();
        });
    }
    _onWindowOpen(event) {
        this._crPage._nextWindowOpenPopupFeatures.push(event.windowFeatures);
    }
    async _onConsoleAPI(event) {
        if (event.executionContextId === 0) {
            // DevTools protocol stores the last 1000 console messages. These
            // messages are always reported even for removed execution contexts. In
            // this case, they are marked with executionContextId = 0 and are
            // reported upon enabling Runtime agent.
            //
            // Ignore these messages since:
            // - there's no execution context we can use to operate with message
            //   arguments
            // - these messages are reported before Playwright clients can subscribe
            //   to the 'console'
            //   page event.
            //
            // @see https://github.com/GoogleChrome/puppeteer/issues/3865
            return;
        }
        const context = this._contextIdToContext.get(event.executionContextId);
        const values = event.args.map(arg => context.createHandle(arg));
        this._page._addConsoleMessage(event.type, values, crProtocolHelper_1.toConsoleMessageLocation(event.stackTrace));
    }
    async _initBinding(binding) {
        const worldName = binding.world === 'utility' ? UTILITY_WORLD_NAME : undefined;
        await Promise.all([
            this._client.send('Runtime.addBinding', { name: binding.name, executionContextName: worldName }),
            this._client.send('Page.addScriptToEvaluateOnNewDocument', { source: binding.source, worldName })
        ]);
    }
    async _onBindingCalled(event) {
        const context = this._contextIdToContext.get(event.executionContextId);
        const pageOrError = await this._crPage.pageOrError();
        if (!(pageOrError instanceof Error))
            await this._page._onBindingCalled(event.payload, context);
    }
    _onDialog(event) {
        if (!this._page._frameManager.frame(this._targetId))
            return; // Our frame/subtree may be gone already.
        this._page.emit(page_1.Page.Events.Dialog, new dialog.Dialog(this._page, event.type, event.message, async (accept, promptText) => {
            await this._client.send('Page.handleJavaScriptDialog', { accept, promptText });
        }, event.defaultPrompt));
    }
    _handleException(exceptionDetails) {
        this._page.emit(page_1.Page.Events.PageError, crProtocolHelper_1.exceptionToError(exceptionDetails));
    }
    async _onTargetCrashed() {
        this._client._markAsCrashed();
        this._page._didCrash();
    }
    _onLogEntryAdded(event) {
        const { level, text, args, source, url, lineNumber } = event.entry;
        if (args)
            args.map(arg => crProtocolHelper_1.releaseObject(this._client, arg.objectId));
        if (source !== 'worker') {
            const location = {
                url: url || '',
                lineNumber: lineNumber || 0,
                columnNumber: 0,
            };
            this._page.emit(page_1.Page.Events.Console, new console_1.ConsoleMessage(this._page, level, text, [], location));
        }
    }
    async _onFileChooserOpened(event) {
        const frame = this._page._frameManager.frame(event.frameId);
        if (!frame)
            return;
        let handle;
        try {
            const utilityContext = await frame._utilityContext();
            handle = await this._adoptBackendNodeId(event.backendNodeId, utilityContext);
        }
        catch (e) {
            // During async processing, frame/context may go away. We should not throw.
            return;
        }
        await this._page._onFileChooserOpened(handle);
    }
    _willBeginDownload() {
        const originPage = this._crPage._initializedPage;
        if (!originPage) {
            // Resume the page creation with an error. The page will automatically close right
            // after the download begins.
            this._firstNonInitialNavigationCommittedReject(new Error('Starting new page download'));
        }
    }
    _onScreencastFrame(payload) {
        this._client.send('Page.screencastFrameAck', { sessionId: payload.sessionId }).catch(() => { });
        const buffer = Buffer.from(payload.data, 'base64');
        this._page.emit(page_1.Page.Events.ScreencastFrame, {
            buffer,
            timestamp: payload.metadata.timestamp,
            width: payload.metadata.deviceWidth,
            height: payload.metadata.deviceHeight,
        });
    }
    async _createVideoRecorder(screencastId, options) {
        utils_1.assert(!this._screencastId);
        const ffmpegPath = this._crPage._browserContext._browser.options.registry.executablePath('ffmpeg');
        if (!ffmpegPath)
            throw new Error('ffmpeg executable was not found');
        if (!utils_1.canAccessFile(ffmpegPath)) {
            let message = '';
            switch (this._page._browserContext._options.sdkLanguage) {
                case 'python':
                    message = 'playwright install ffmpeg';
                    break;
                case 'python-async':
                    message = 'playwright install ffmpeg';
                    break;
                case 'javascript':
                    message = 'npx playwright install ffmpeg';
                    break;
                case 'java':
                    message = 'mvn exec:java -e -Dexec.mainClass=com.microsoft.playwright.CLI -Dexec.args="install ffmpeg"';
                    break;
            }
            throw new Error(`
============================================================
  Please install ffmpeg in order to record video.

  $ ${message}
============================================================
      `);
        }
        this._videoRecorder = await videoRecorder_1.VideoRecorder.launch(this._crPage._page, ffmpegPath, options);
        this._screencastId = screencastId;
    }
    async _startVideoRecording(options) {
        const screencastId = this._screencastId;
        utils_1.assert(screencastId);
        this._page.once(page_1.Page.Events.Close, () => this._stopVideoRecording().catch(() => { }));
        const gotFirstFrame = new Promise(f => this._client.once('Page.screencastFrame', f));
        await this._startScreencast(this._videoRecorder, {
            format: 'jpeg',
            quality: 90,
            maxWidth: options.width,
            maxHeight: options.height,
        });
        // Wait for the first frame before reporting video to the client.
        gotFirstFrame.then(() => {
            this._crPage._browserContext._browser._videoStarted(this._crPage._browserContext, screencastId, options.outputFile, this._crPage.pageOrError());
        });
    }
    async _stopVideoRecording() {
        if (!this._screencastId)
            return;
        const screencastId = this._screencastId;
        this._screencastId = null;
        const recorder = this._videoRecorder;
        this._videoRecorder = null;
        await this._stopScreencast(recorder);
        await recorder.stop().catch(() => { });
        // Keep the video artifact in the map utntil encoding is fully finished, if the context
        // starts closing before the video is fully written to disk it will wait for it.
        const video = this._crPage._browserContext._browser._takeVideo(screencastId);
        video === null || video === void 0 ? void 0 : video.reportFinished();
    }
    async _startScreencast(client, options = {}) {
        this._screencastClients.add(client);
        if (this._screencastClients.size === 1)
            await this._client.send('Page.startScreencast', options);
    }
    async _stopScreencast(client) {
        this._screencastClients.delete(client);
        if (!this._screencastClients.size)
            await this._client._sendMayFail('Page.stopScreencast');
    }
    async _updateExtraHTTPHeaders(initial) {
        const headers = network.mergeHeaders([
            this._crPage._browserContext._options.extraHTTPHeaders,
            this._page._state.extraHTTPHeaders
        ]);
        if (!initial || headers.length)
            await this._client.send('Network.setExtraHTTPHeaders', { headers: utils_1.headersArrayToObject(headers, false /* lowerCase */) });
    }
    async _updateGeolocation(initial) {
        const geolocation = this._crPage._browserContext._options.geolocation;
        if (!initial || geolocation)
            await this._client.send('Emulation.setGeolocationOverride', geolocation || {});
    }
    async _updateOffline(initial) {
        const offline = !!this._crPage._browserContext._options.offline;
        if (!initial || offline)
            await this._networkManager.setOffline(offline);
    }
    async _updateHttpCredentials(initial) {
        const credentials = this._crPage._browserContext._options.httpCredentials || null;
        if (!initial || credentials)
            await this._networkManager.authenticate(credentials);
    }
    async _updateViewport() {
        if (this._crPage._browserContext._browser.isClank())
            return;
        utils_1.assert(this._isMainFrame());
        const options = this._crPage._browserContext._options;
        const emulatedSize = this._page._state.emulatedSize;
        if (emulatedSize === null)
            return;
        const viewportSize = emulatedSize.viewport;
        const screenSize = emulatedSize.screen;
        const isLandscape = viewportSize.width > viewportSize.height;
        const promises = [
            this._client.send('Emulation.setDeviceMetricsOverride', {
                mobile: !!options.isMobile,
                width: viewportSize.width,
                height: viewportSize.height,
                screenWidth: screenSize.width,
                screenHeight: screenSize.height,
                deviceScaleFactor: options.deviceScaleFactor || 1,
                screenOrientation: isLandscape ? { angle: 90, type: 'landscapePrimary' } : { angle: 0, type: 'portraitPrimary' },
            }),
        ];
        if (this._windowId) {
            let insets = { width: 0, height: 0 };
            if (this._crPage._browserContext._browser.options.headful) {
                // TODO: popup windows have their own insets.
                insets = { width: 24, height: 88 };
                if (process.platform === 'win32')
                    insets = { width: 16, height: 88 };
                else if (process.platform === 'linux')
                    insets = { width: 8, height: 85 };
                else if (process.platform === 'darwin')
                    insets = { width: 2, height: 80 };
            }
            promises.push(this.setWindowBounds({
                width: viewportSize.width + insets.width,
                height: viewportSize.height + insets.height
            }));
        }
        await Promise.all(promises);
    }
    async windowBounds() {
        const { bounds } = await this._client.send('Browser.getWindowBounds', {
            windowId: this._windowId
        });
        return bounds;
    }
    async setWindowBounds(bounds) {
        return await this._client.send('Browser.setWindowBounds', {
            windowId: this._windowId,
            bounds
        });
    }
    async _updateEmulateMedia(initial) {
        if (this._crPage._browserContext._browser.isClank())
            return;
        const colorScheme = this._page._state.colorScheme === null ? '' : this._page._state.colorScheme;
        const reducedMotion = this._page._state.reducedMotion === null ? '' : this._page._state.reducedMotion;
        const features = [
            { name: 'prefers-color-scheme', value: colorScheme },
            { name: 'prefers-reduced-motion', value: reducedMotion },
        ];
        // Empty string disables the override.
        await this._client.send('Emulation.setEmulatedMedia', { media: this._page._state.mediaType || '', features });
    }
    async _updateRequestInterception(initial) {
        await this._networkManager.setRequestInterception(this._page._needsRequestInterception());
    }
    async _setFileChooserIntercepted(enabled) {
        await this._client.send('Page.setInterceptFileChooserDialog', { enabled }).catch(e => { }); // target can be closed.
    }
    async _evaluateOnNewDocument(source, world) {
        const worldName = world === 'utility' ? UTILITY_WORLD_NAME : undefined;
        await this._client.send('Page.addScriptToEvaluateOnNewDocument', { source, worldName });
    }
    async _getContentFrame(handle) {
        const nodeInfo = await this._client.send('DOM.describeNode', {
            objectId: handle._objectId
        });
        if (!nodeInfo || typeof nodeInfo.node.frameId !== 'string')
            return null;
        return this._page._frameManager.frame(nodeInfo.node.frameId);
    }
    async _getOwnerFrame(handle) {
        // document.documentElement has frameId of the owner frame.
        const documentElement = await handle.evaluateHandle(node => {
            const doc = node;
            if (doc.documentElement && doc.documentElement.ownerDocument === doc)
                return doc.documentElement;
            return node.ownerDocument ? node.ownerDocument.documentElement : null;
        });
        if (!documentElement)
            return null;
        if (!documentElement._objectId)
            return null;
        const nodeInfo = await this._client.send('DOM.describeNode', {
            objectId: documentElement._objectId
        });
        const frameId = nodeInfo && typeof nodeInfo.node.frameId === 'string' ?
            nodeInfo.node.frameId : null;
        documentElement.dispose();
        return frameId;
    }
    async _getBoundingBox(handle) {
        const result = await this._client._sendMayFail('DOM.getBoxModel', {
            objectId: handle._objectId
        });
        if (!result)
            return null;
        const quad = result.model.border;
        const x = Math.min(quad[0], quad[2], quad[4], quad[6]);
        const y = Math.min(quad[1], quad[3], quad[5], quad[7]);
        const width = Math.max(quad[0], quad[2], quad[4], quad[6]) - x;
        const height = Math.max(quad[1], quad[3], quad[5], quad[7]) - y;
        const position = await this._framePosition();
        if (!position)
            return null;
        return { x: x + position.x, y: y + position.y, width, height };
    }
    async _framePosition() {
        const frame = this._page._frameManager.frame(this._targetId);
        if (!frame)
            return null;
        if (frame === this._page.mainFrame())
            return { x: 0, y: 0 };
        const element = await frame.frameElement();
        const box = await element.boundingBox();
        return box;
    }
    async _scrollRectIntoViewIfNeeded(handle, rect) {
        return await this._client.send('DOM.scrollIntoViewIfNeeded', {
            objectId: handle._objectId,
            rect,
        }).then(() => 'done').catch(e => {
            if (e instanceof Error && e.message.includes('Node does not have a layout object'))
                return 'error:notvisible';
            if (e instanceof Error && e.message.includes('Node is detached from document'))
                return 'error:notconnected';
            throw e;
        });
    }
    async _getContentQuads(handle) {
        const result = await this._client._sendMayFail('DOM.getContentQuads', {
            objectId: handle._objectId
        });
        if (!result)
            return null;
        const position = await this._framePosition();
        if (!position)
            return null;
        return result.quads.map(quad => [
            { x: quad[0] + position.x, y: quad[1] + position.y },
            { x: quad[2] + position.x, y: quad[3] + position.y },
            { x: quad[4] + position.x, y: quad[5] + position.y },
            { x: quad[6] + position.x, y: quad[7] + position.y }
        ]);
    }
    async _adoptElementHandle(handle, to) {
        const nodeInfo = await this._client.send('DOM.describeNode', {
            objectId: handle._objectId,
        });
        return this._adoptBackendNodeId(nodeInfo.node.backendNodeId, to);
    }
    async _adoptBackendNodeId(backendNodeId, to) {
        const result = await this._client._sendMayFail('DOM.resolveNode', {
            backendNodeId,
            executionContextId: to._delegate._contextId,
        });
        if (!result || result.object.subtype === 'null')
            throw new Error(dom.kUnableToAdoptErrorMessage);
        return to.createHandle(result.object).asElement();
    }
}
async function emulateLocale(session, locale) {
    try {
        await session.send('Emulation.setLocaleOverride', { locale });
    }
    catch (exception) {
        // All pages in the same renderer share locale. All such pages belong to the same
        // context and if locale is overridden for one of them its value is the same as
        // we are trying to set so it's not a problem.
        if (exception.message.includes('Another locale override is already in effect'))
            return;
        throw exception;
    }
}
async function emulateTimezone(session, timezoneId) {
    try {
        await session.send('Emulation.setTimezoneOverride', { timezoneId: timezoneId });
    }
    catch (exception) {
        if (exception.message.includes('Timezone override is already in effect'))
            return;
        if (exception.message.includes('Invalid timezone'))
            throw new Error(`Invalid timezone ID: ${timezoneId}`);
        throw exception;
    }
}
//# sourceMappingURL=crPage.js.map