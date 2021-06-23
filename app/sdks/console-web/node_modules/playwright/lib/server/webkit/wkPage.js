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
exports.WKPage = void 0;
const jpeg = __importStar(require("jpeg-js"));
const path_1 = __importDefault(require("path"));
const png = __importStar(require("pngjs"));
const stackTrace_1 = require("../../utils/stackTrace");
const registry_1 = require("../../utils/registry");
const utils_1 = require("../../utils/utils");
const dialog = __importStar(require("../dialog"));
const dom = __importStar(require("../dom"));
const helper_1 = require("../helper");
const javascript_1 = require("../javascript");
const network = __importStar(require("../network"));
const page_1 = require("../page");
const wkAccessibility_1 = require("./wkAccessibility");
const wkConnection_1 = require("./wkConnection");
const wkExecutionContext_1 = require("./wkExecutionContext");
const wkInput_1 = require("./wkInput");
const wkInterceptableRequest_1 = require("./wkInterceptableRequest");
const wkProvisionalPage_1 = require("./wkProvisionalPage");
const wkWorkers_1 = require("./wkWorkers");
const debugLogger_1 = require("../../utils/debugLogger");
const UTILITY_WORLD_NAME = '__playwright_utility_world__';
const BINDING_CALL_MESSAGE = '__playwright_binding_call__';
class WKPage {
    constructor(browserContext, pageProxySession, opener) {
        this._provisionalPage = null;
        this._pagePromiseCallback = () => { };
        this._requestIdToRequest = new Map();
        this._sessionListeners = [];
        this._initializedPage = null;
        this._firstNonInitialNavigationCommittedFulfill = () => { };
        this._firstNonInitialNavigationCommittedReject = (e) => { };
        this._lastConsoleMessage = null;
        this._recordingVideoFile = null;
        this._screencastGeneration = 0;
        this._pageProxySession = pageProxySession;
        this._opener = opener;
        this.rawKeyboard = new wkInput_1.RawKeyboardImpl(pageProxySession);
        this.rawMouse = new wkInput_1.RawMouseImpl(pageProxySession);
        this.rawTouchscreen = new wkInput_1.RawTouchscreenImpl(pageProxySession);
        this._contextIdToContext = new Map();
        this._page = new page_1.Page(this, browserContext);
        this._workers = new wkWorkers_1.WKWorkers(this._page);
        this._session = undefined;
        this._browserContext = browserContext;
        this._page.on(page_1.Page.Events.FrameDetached, (frame) => this._removeContextsForFrame(frame, false));
        this._eventListeners = [
            helper_1.helper.addEventListener(this._pageProxySession, 'Target.targetCreated', this._onTargetCreated.bind(this)),
            helper_1.helper.addEventListener(this._pageProxySession, 'Target.targetDestroyed', this._onTargetDestroyed.bind(this)),
            helper_1.helper.addEventListener(this._pageProxySession, 'Target.dispatchMessageFromTarget', this._onDispatchMessageFromTarget.bind(this)),
            helper_1.helper.addEventListener(this._pageProxySession, 'Target.didCommitProvisionalTarget', this._onDidCommitProvisionalTarget.bind(this)),
            helper_1.helper.addEventListener(this._pageProxySession, 'Screencast.screencastFrame', this._onScreencastFrame.bind(this)),
        ];
        this._pagePromise = new Promise(f => this._pagePromiseCallback = f);
        this._firstNonInitialNavigationCommittedPromise = new Promise((f, r) => {
            this._firstNonInitialNavigationCommittedFulfill = f;
            this._firstNonInitialNavigationCommittedReject = r;
        });
        if (opener && !browserContext._options.noDefaultViewport && opener._nextWindowOpenPopupFeatures) {
            const viewportSize = helper_1.helper.getViewportSizeFromWindowFeatures(opener._nextWindowOpenPopupFeatures);
            opener._nextWindowOpenPopupFeatures = undefined;
            if (viewportSize)
                this._page._state.emulatedSize = { viewport: viewportSize, screen: viewportSize };
        }
    }
    async _initializePageProxySession() {
        const promises = [
            this._pageProxySession.send('Dialog.enable'),
            this._pageProxySession.send('Emulation.setActiveAndFocused', { active: true }),
        ];
        const contextOptions = this._browserContext._options;
        if (contextOptions.javaScriptEnabled === false)
            promises.push(this._pageProxySession.send('Emulation.setJavaScriptEnabled', { enabled: false }));
        promises.push(this._updateViewport());
        promises.push(this.updateHttpCredentials());
        if (this._browserContext._permissions.size) {
            for (const [key, value] of this._browserContext._permissions)
                promises.push(this._grantPermissions(key, value));
        }
        if (this._browserContext._options.recordVideo) {
            const outputFile = path_1.default.join(this._browserContext._options.recordVideo.dir, utils_1.createGuid() + '.webm');
            promises.push(this._browserContext._ensureVideosPath().then(() => {
                return this._startVideo({
                    // validateBrowserContextOptions ensures correct video size.
                    ...this._browserContext._options.recordVideo.size,
                    outputFile,
                });
            }));
        }
        await Promise.all(promises);
    }
    _setSession(session) {
        helper_1.helper.removeEventListeners(this._sessionListeners);
        this._session = session;
        this.rawKeyboard.setSession(session);
        this._addSessionListeners();
        this._workers.setSession(session);
    }
    // This method is called for provisional targets as well. The session passed as the parameter
    // may be different from the current session and may be destroyed without becoming current.
    async _initializeSession(session, provisional, resourceTreeHandler) {
        await this._initializeSessionMayThrow(session, resourceTreeHandler).catch(e => {
            // Provisional session can be disposed at any time, for example due to new navigation initiating
            // a new provisional page.
            if (provisional && session.isDisposed())
                return;
            // Swallow initialization errors due to newer target swap in,
            // since we will reinitialize again.
            if (this._session === session)
                throw e;
        });
    }
    async _initializeSessionMayThrow(session, resourceTreeHandler) {
        const [, frameTree] = await Promise.all([
            // Page agent must be enabled before Runtime.
            session.send('Page.enable'),
            session.send('Page.getResourceTree'),
        ]);
        resourceTreeHandler(frameTree);
        const promises = [
            // Resource tree should be received before first execution context.
            session.send('Runtime.enable'),
            session.send('Page.createUserWorld', { name: UTILITY_WORLD_NAME }).catch(_ => { }),
            session.send('Console.enable'),
            session.send('Network.enable'),
            this._workers.initializeSession(session)
        ];
        if (this._page._needsRequestInterception()) {
            promises.push(session.send('Network.setInterceptionEnabled', { enabled: true }));
            promises.push(session.send('Network.addInterception', { url: '.*', stage: 'request', isRegex: true }));
        }
        const contextOptions = this._browserContext._options;
        if (contextOptions.userAgent)
            promises.push(session.send('Page.overrideUserAgent', { value: contextOptions.userAgent }));
        if (this._page._state.mediaType || this._page._state.colorScheme || this._page._state.reducedMotion)
            promises.push(WKPage._setEmulateMedia(session, this._page._state.mediaType, this._page._state.colorScheme, this._page._state.reducedMotion));
        for (const world of ['main', 'utility']) {
            const bootstrapScript = this._calculateBootstrapScript(world);
            if (bootstrapScript.length)
                promises.push(session.send('Page.setBootstrapScript', { source: bootstrapScript, worldName: webkitWorldName(world) }));
            this._page.frames().map(frame => frame.evaluateExpression(bootstrapScript, false, undefined, world).catch(e => { }));
        }
        if (contextOptions.bypassCSP)
            promises.push(session.send('Page.setBypassCSP', { enabled: true }));
        if (this._page._state.emulatedSize) {
            promises.push(session.send('Page.setScreenSizeOverride', {
                width: this._page._state.emulatedSize.screen.width,
                height: this._page._state.emulatedSize.screen.height,
            }));
        }
        promises.push(this.updateEmulateMedia());
        promises.push(session.send('Network.setExtraHTTPHeaders', { headers: utils_1.headersArrayToObject(this._calculateExtraHTTPHeaders(), false /* lowerCase */) }));
        if (contextOptions.offline)
            promises.push(session.send('Network.setEmulateOfflineState', { offline: true }));
        promises.push(session.send('Page.setTouchEmulationEnabled', { enabled: !!contextOptions.hasTouch }));
        if (contextOptions.timezoneId) {
            promises.push(session.send('Page.setTimeZone', { timeZone: contextOptions.timezoneId }).
                catch(e => { throw new Error(`Invalid timezone ID: ${contextOptions.timezoneId}`); }));
        }
        await Promise.all(promises);
    }
    _onDidCommitProvisionalTarget(event) {
        const { oldTargetId, newTargetId } = event;
        utils_1.assert(this._provisionalPage);
        utils_1.assert(this._provisionalPage._session.sessionId === newTargetId, 'Unknown new target: ' + newTargetId);
        utils_1.assert(this._session.sessionId === oldTargetId, 'Unknown old target: ' + oldTargetId);
        this._session.errorText = javascript_1.kSwappedOutErrorMessage;
        const newSession = this._provisionalPage._session;
        this._provisionalPage.commit();
        this._provisionalPage.dispose();
        this._provisionalPage = null;
        this._setSession(newSession);
    }
    _onTargetDestroyed(event) {
        const { targetId, crashed } = event;
        if (this._provisionalPage && this._provisionalPage._session.sessionId === targetId) {
            this._provisionalPage._session.dispose(false);
            this._provisionalPage.dispose();
            this._provisionalPage = null;
        }
        else if (this._session.sessionId === targetId) {
            this._session.dispose(false);
            helper_1.helper.removeEventListeners(this._sessionListeners);
            if (crashed) {
                this._session.markAsCrashed();
                this._page._didCrash();
            }
        }
    }
    didClose() {
        this._page._didClose();
    }
    dispose(disconnected) {
        this._pageProxySession.dispose(disconnected);
        helper_1.helper.removeEventListeners(this._sessionListeners);
        helper_1.helper.removeEventListeners(this._eventListeners);
        if (this._session)
            this._session.dispose(disconnected);
        if (this._provisionalPage) {
            this._provisionalPage._session.dispose(disconnected);
            this._provisionalPage.dispose();
            this._provisionalPage = null;
        }
        this._page._didDisconnect();
        this._firstNonInitialNavigationCommittedReject(new Error('Page closed'));
    }
    dispatchMessageToSession(message) {
        this._pageProxySession.dispatchMessage(message);
    }
    handleProvisionalLoadFailed(event) {
        if (!this._initializedPage) {
            this._firstNonInitialNavigationCommittedReject(new Error('Initial load failed'));
            return;
        }
        if (!this._provisionalPage)
            return;
        let errorText = event.error;
        if (errorText.includes('cancelled'))
            errorText += '; maybe frame was detached?';
        this._page._frameManager.frameAbortedNavigation(this._page.mainFrame()._id, errorText, event.loaderId);
    }
    handleWindowOpen(event) {
        utils_1.debugAssert(!this._nextWindowOpenPopupFeatures);
        this._nextWindowOpenPopupFeatures = event.windowFeatures;
    }
    async pageOrError() {
        return this._pagePromise;
    }
    async _onTargetCreated(event) {
        const { targetInfo } = event;
        const session = new wkConnection_1.WKSession(this._pageProxySession.connection, targetInfo.targetId, `The ${targetInfo.type} has been closed.`, (message) => {
            this._pageProxySession.send('Target.sendMessageToTarget', {
                message: JSON.stringify(message), targetId: targetInfo.targetId
            }).catch(e => {
                session.dispatchMessage({ id: message.id, error: { message: e.message } });
            });
        });
        utils_1.assert(targetInfo.type === 'page', 'Only page targets are expected in WebKit, received: ' + targetInfo.type);
        if (!targetInfo.isProvisional) {
            utils_1.assert(!this._initializedPage);
            let pageOrError;
            try {
                this._setSession(session);
                await Promise.all([
                    this._initializePageProxySession(),
                    this._initializeSession(session, false, ({ frameTree }) => this._handleFrameTree(frameTree)),
                ]);
                pageOrError = this._page;
            }
            catch (e) {
                pageOrError = e;
            }
            if (targetInfo.isPaused)
                this._pageProxySession.sendMayFail('Target.resume', { targetId: targetInfo.targetId });
            if ((pageOrError instanceof page_1.Page) && this._page.mainFrame().url() === '') {
                try {
                    // Initial empty page has an empty url. We should wait until the first real url has been loaded,
                    // even if that url is about:blank. This is especially important for popups, where we need the
                    // actual url before interacting with it.
                    await this._firstNonInitialNavigationCommittedPromise;
                }
                catch (e) {
                    pageOrError = e;
                }
            }
            else {
                // Avoid rejection on disconnect.
                this._firstNonInitialNavigationCommittedPromise.catch(() => { });
            }
            await this._page.initOpener(this._opener);
            // Note: it is important to call |reportAsNew| before resolving pageOrError promise,
            // so that anyone who awaits pageOrError got a ready and reported page.
            this._initializedPage = pageOrError instanceof page_1.Page ? pageOrError : null;
            this._page.reportAsNew(pageOrError instanceof page_1.Page ? undefined : pageOrError);
            this._pagePromiseCallback(pageOrError);
        }
        else {
            utils_1.assert(targetInfo.isProvisional);
            utils_1.assert(!this._provisionalPage);
            this._provisionalPage = new wkProvisionalPage_1.WKProvisionalPage(session, this);
            if (targetInfo.isPaused) {
                this._provisionalPage.initializationPromise.then(() => {
                    this._pageProxySession.sendMayFail('Target.resume', { targetId: targetInfo.targetId });
                });
            }
        }
    }
    _onDispatchMessageFromTarget(event) {
        const { targetId, message } = event;
        if (this._provisionalPage && this._provisionalPage._session.sessionId === targetId)
            this._provisionalPage._session.dispatchMessage(JSON.parse(message));
        else if (this._session.sessionId === targetId)
            this._session.dispatchMessage(JSON.parse(message));
        else
            throw new Error('Unknown target: ' + targetId);
    }
    _addSessionListeners() {
        // TODO: remove Page.willRequestOpenWindow and Page.didRequestOpenWindow from the protocol.
        this._sessionListeners = [
            helper_1.helper.addEventListener(this._session, 'Page.frameNavigated', event => this._onFrameNavigated(event.frame, false)),
            helper_1.helper.addEventListener(this._session, 'Page.navigatedWithinDocument', event => this._onFrameNavigatedWithinDocument(event.frameId, event.url)),
            helper_1.helper.addEventListener(this._session, 'Page.frameAttached', event => this._onFrameAttached(event.frameId, event.parentFrameId)),
            helper_1.helper.addEventListener(this._session, 'Page.frameDetached', event => this._onFrameDetached(event.frameId)),
            helper_1.helper.addEventListener(this._session, 'Page.frameScheduledNavigation', event => this._onFrameScheduledNavigation(event.frameId)),
            helper_1.helper.addEventListener(this._session, 'Page.frameStoppedLoading', event => this._onFrameStoppedLoading(event.frameId)),
            helper_1.helper.addEventListener(this._session, 'Page.loadEventFired', event => this._onLifecycleEvent(event.frameId, 'load')),
            helper_1.helper.addEventListener(this._session, 'Page.domContentEventFired', event => this._onLifecycleEvent(event.frameId, 'domcontentloaded')),
            helper_1.helper.addEventListener(this._session, 'Runtime.executionContextCreated', event => this._onExecutionContextCreated(event.context)),
            helper_1.helper.addEventListener(this._session, 'Console.messageAdded', event => this._onConsoleMessage(event)),
            helper_1.helper.addEventListener(this._session, 'Console.messageRepeatCountUpdated', event => this._onConsoleRepeatCountUpdated(event)),
            helper_1.helper.addEventListener(this._pageProxySession, 'Dialog.javascriptDialogOpening', event => this._onDialog(event)),
            helper_1.helper.addEventListener(this._session, 'Page.fileChooserOpened', event => this._onFileChooserOpened(event)),
            helper_1.helper.addEventListener(this._session, 'Network.requestWillBeSent', e => this._onRequestWillBeSent(this._session, e)),
            helper_1.helper.addEventListener(this._session, 'Network.requestIntercepted', e => this._onRequestIntercepted(e)),
            helper_1.helper.addEventListener(this._session, 'Network.responseReceived', e => this._onResponseReceived(e)),
            helper_1.helper.addEventListener(this._session, 'Network.loadingFinished', e => this._onLoadingFinished(e)),
            helper_1.helper.addEventListener(this._session, 'Network.loadingFailed', e => this._onLoadingFailed(e)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketCreated', e => this._page._frameManager.onWebSocketCreated(e.requestId, e.url)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketWillSendHandshakeRequest', e => this._page._frameManager.onWebSocketRequest(e.requestId)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketHandshakeResponseReceived', e => this._page._frameManager.onWebSocketResponse(e.requestId, e.response.status, e.response.statusText)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketFrameSent', e => e.response.payloadData && this._page._frameManager.onWebSocketFrameSent(e.requestId, e.response.opcode, e.response.payloadData)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketFrameReceived', e => e.response.payloadData && this._page._frameManager.webSocketFrameReceived(e.requestId, e.response.opcode, e.response.payloadData)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketClosed', e => this._page._frameManager.webSocketClosed(e.requestId)),
            helper_1.helper.addEventListener(this._session, 'Network.webSocketFrameError', e => this._page._frameManager.webSocketError(e.requestId, e.errorMessage)),
        ];
    }
    async _updateState(method, params) {
        await this._forAllSessions(session => session.send(method, params).then());
    }
    async _forAllSessions(callback) {
        const sessions = [
            this._session
        ];
        // If the state changes during provisional load, push it to the provisional page
        // as well to always be in sync with the backend.
        if (this._provisionalPage)
            sessions.push(this._provisionalPage._session);
        await Promise.all(sessions.map(session => callback(session).catch(e => { })));
    }
    _onFrameScheduledNavigation(frameId) {
        this._page._frameManager.frameRequestedNavigation(frameId);
    }
    _onFrameStoppedLoading(frameId) {
        this._page._frameManager.frameStoppedLoading(frameId);
    }
    _onLifecycleEvent(frameId, event) {
        this._page._frameManager.frameLifecycleEvent(frameId, event);
    }
    _handleFrameTree(frameTree) {
        this._onFrameAttached(frameTree.frame.id, frameTree.frame.parentId || null);
        this._onFrameNavigated(frameTree.frame, true);
        this._page._frameManager.frameLifecycleEvent(frameTree.frame.id, 'domcontentloaded');
        this._page._frameManager.frameLifecycleEvent(frameTree.frame.id, 'load');
        if (!frameTree.childFrames)
            return;
        for (const child of frameTree.childFrames)
            this._handleFrameTree(child);
    }
    _onFrameAttached(frameId, parentFrameId) {
        return this._page._frameManager.frameAttached(frameId, parentFrameId);
    }
    _onFrameNavigated(framePayload, initial) {
        const frame = this._page._frameManager.frame(framePayload.id);
        utils_1.assert(frame);
        this._removeContextsForFrame(frame, true);
        if (!framePayload.parentId)
            this._workers.clear();
        this._page._frameManager.frameCommittedNewDocumentNavigation(framePayload.id, framePayload.url, framePayload.name || '', framePayload.loaderId, initial);
        if (!initial)
            this._firstNonInitialNavigationCommittedFulfill();
    }
    _onFrameNavigatedWithinDocument(frameId, url) {
        this._page._frameManager.frameCommittedSameDocumentNavigation(frameId, url);
    }
    _onFrameDetached(frameId) {
        this._page._frameManager.frameDetached(frameId);
    }
    _removeContextsForFrame(frame, notifyFrame) {
        for (const [contextId, context] of this._contextIdToContext) {
            if (context.frame === frame) {
                context._delegate._dispose();
                this._contextIdToContext.delete(contextId);
                if (notifyFrame)
                    frame._contextDestroyed(context);
            }
        }
    }
    _onExecutionContextCreated(contextPayload) {
        if (this._contextIdToContext.has(contextPayload.id))
            return;
        const frame = this._page._frameManager.frame(contextPayload.frameId);
        if (!frame)
            return;
        const delegate = new wkExecutionContext_1.WKExecutionContext(this._session, contextPayload.id);
        let worldName = null;
        if (contextPayload.type === 'normal')
            worldName = 'main';
        else if (contextPayload.type === 'user' && contextPayload.name === UTILITY_WORLD_NAME)
            worldName = 'utility';
        const context = new dom.FrameExecutionContext(delegate, frame, worldName);
        if (worldName)
            frame._contextCreated(worldName, context);
        if (contextPayload.type === 'normal' && frame === this._page.mainFrame())
            this._mainFrameContextId = contextPayload.id;
        this._contextIdToContext.set(contextPayload.id, context);
    }
    async navigateFrame(frame, url, referrer) {
        if (this._pageProxySession.isDisposed())
            throw new Error('Target closed');
        const pageProxyId = this._pageProxySession.sessionId;
        const result = await this._pageProxySession.connection.browserSession.send('Playwright.navigate', { url, pageProxyId, frameId: frame._id, referrer });
        return { newDocumentId: result.loaderId };
    }
    _onConsoleMessage(event) {
        // Note: do no introduce await in this function, otherwise we lose the ordering.
        // For example, frame.setContent relies on this.
        const { type, level, text, parameters, url, line: lineNumber, column: columnNumber, source } = event.message;
        if (level === 'debug' && parameters && parameters[0].value === BINDING_CALL_MESSAGE) {
            const parsedObjectId = JSON.parse(parameters[1].objectId);
            const context = this._contextIdToContext.get(parsedObjectId.injectedScriptId);
            this.pageOrError().then(pageOrError => {
                if (!(pageOrError instanceof Error))
                    this._page._onBindingCalled(parameters[2].value, context);
            });
            return;
        }
        if (level === 'error' && source === 'javascript') {
            const { name, message } = stackTrace_1.splitErrorMessage(text);
            const error = new Error(message);
            if (event.message.stackTrace) {
                error.stack = event.message.stackTrace.map(callFrame => {
                    return `${callFrame.functionName}@${callFrame.url}:${callFrame.lineNumber}:${callFrame.columnNumber}`;
                }).join('\n');
            }
            else {
                error.stack = '';
            }
            error.name = name;
            this._page.emit(page_1.Page.Events.PageError, error);
            return;
        }
        let derivedType = type || '';
        if (type === 'log')
            derivedType = level;
        else if (type === 'timing')
            derivedType = 'timeEnd';
        const handles = (parameters || []).map(p => {
            let context = null;
            if (p.objectId) {
                const objectId = JSON.parse(p.objectId);
                context = this._contextIdToContext.get(objectId.injectedScriptId);
            }
            else {
                context = this._contextIdToContext.get(this._mainFrameContextId);
            }
            return context.createHandle(p);
        });
        this._lastConsoleMessage = {
            derivedType,
            text,
            handles,
            count: 0,
            location: {
                url: url || '',
                lineNumber: (lineNumber || 1) - 1,
                columnNumber: (columnNumber || 1) - 1,
            }
        };
        this._onConsoleRepeatCountUpdated({ count: 1 });
    }
    _onConsoleRepeatCountUpdated(event) {
        if (this._lastConsoleMessage) {
            const { derivedType, text, handles, count, location } = this._lastConsoleMessage;
            for (let i = count; i < event.count; ++i)
                this._page._addConsoleMessage(derivedType, handles, location, handles.length ? undefined : text);
            this._lastConsoleMessage.count = event.count;
        }
    }
    _onDialog(event) {
        this._page.emit(page_1.Page.Events.Dialog, new dialog.Dialog(this._page, event.type, event.message, async (accept, promptText) => {
            await this._pageProxySession.send('Dialog.handleJavaScriptDialog', { accept, promptText });
        }, event.defaultPrompt));
    }
    async _onFileChooserOpened(event) {
        let handle;
        try {
            const context = await this._page._frameManager.frame(event.frameId)._mainContext();
            handle = context.createHandle(event.element).asElement();
        }
        catch (e) {
            // During async processing, frame/context may go away. We should not throw.
            return;
        }
        await this._page._onFileChooserOpened(handle);
    }
    static async _setEmulateMedia(session, mediaType, colorScheme, reducedMotion) {
        const promises = [];
        promises.push(session.send('Page.setEmulatedMedia', { media: mediaType || '' }));
        let appearance = undefined;
        switch (colorScheme) {
            case 'light':
                appearance = 'Light';
                break;
            case 'dark':
                appearance = 'Dark';
                break;
        }
        promises.push(session.send('Page.setForcedAppearance', { appearance }));
        let reducedMotionWk = undefined;
        switch (reducedMotion) {
            case 'reduce':
                reducedMotionWk = 'Reduce';
                break;
            case 'no-preference':
                reducedMotionWk = 'NoPreference';
                break;
        }
        promises.push(session.send('Page.setForcedReducedMotion', { reducedMotion: reducedMotionWk }));
        await Promise.all(promises);
    }
    async updateExtraHTTPHeaders() {
        await this._updateState('Network.setExtraHTTPHeaders', { headers: utils_1.headersArrayToObject(this._calculateExtraHTTPHeaders(), false /* lowerCase */) });
    }
    _calculateExtraHTTPHeaders() {
        const locale = this._browserContext._options.locale;
        const headers = network.mergeHeaders([
            this._browserContext._options.extraHTTPHeaders,
            this._page._state.extraHTTPHeaders,
            locale ? network.singleHeader('Accept-Language', locale) : undefined,
        ]);
        return headers;
    }
    async updateEmulateMedia() {
        const colorScheme = this._page._state.colorScheme;
        const reducedMotion = this._page._state.reducedMotion;
        await this._forAllSessions(session => WKPage._setEmulateMedia(session, this._page._state.mediaType, colorScheme, reducedMotion));
    }
    async setEmulatedSize(emulatedSize) {
        utils_1.assert(this._page._state.emulatedSize === emulatedSize);
        await this._updateViewport();
    }
    async bringToFront() {
        this._pageProxySession.send('Target.activate', {
            targetId: this._session.sessionId
        });
    }
    async _updateViewport() {
        const options = this._browserContext._options;
        const deviceSize = this._page._state.emulatedSize;
        if (deviceSize === null)
            return;
        const viewportSize = deviceSize.viewport;
        const screenSize = deviceSize.screen;
        const promises = [
            this._pageProxySession.send('Emulation.setDeviceMetricsOverride', {
                width: viewportSize.width,
                height: viewportSize.height,
                fixedLayout: !!options.isMobile,
                deviceScaleFactor: options.deviceScaleFactor || 1
            }),
            this._session.send('Page.setScreenSizeOverride', {
                width: screenSize.width,
                height: screenSize.height,
            }),
        ];
        if (options.isMobile) {
            const angle = viewportSize.width > viewportSize.height ? 90 : 0;
            promises.push(this._session.send('Page.setOrientationOverride', { angle }));
        }
        await Promise.all(promises);
    }
    async updateRequestInterception() {
        const enabled = this._page._needsRequestInterception();
        await Promise.all([
            this._updateState('Network.setInterceptionEnabled', { enabled }),
            this._updateState('Network.addInterception', { url: '.*', stage: 'request', isRegex: true })
        ]);
    }
    async updateOffline() {
        await this._updateState('Network.setEmulateOfflineState', { offline: !!this._browserContext._options.offline });
    }
    async updateHttpCredentials() {
        const credentials = this._browserContext._options.httpCredentials || { username: '', password: '' };
        await this._pageProxySession.send('Emulation.setAuthCredentials', { username: credentials.username, password: credentials.password });
    }
    async setFileChooserIntercepted(enabled) {
        await this._session.send('Page.setInterceptFileChooserDialog', { enabled }).catch(e => { }); // target can be closed.
    }
    async reload() {
        await this._session.send('Page.reload');
    }
    goBack() {
        return this._session.send('Page.goBack').then(() => true).catch(error => {
            if (error instanceof Error && error.message.includes(`Protocol error (Page.goBack): Failed to go`))
                return false;
            throw error;
        });
    }
    goForward() {
        return this._session.send('Page.goForward').then(() => true).catch(error => {
            if (error instanceof Error && error.message.includes(`Protocol error (Page.goForward): Failed to go`))
                return false;
            throw error;
        });
    }
    async exposeBinding(binding) {
        await this._updateBootstrapScript(binding.world);
        await this._evaluateBindingScript(binding);
    }
    async _evaluateBindingScript(binding) {
        const script = this._bindingToScript(binding);
        await Promise.all(this._page.frames().map(frame => frame.evaluateExpression(script, false, {}, binding.world).catch(e => { })));
    }
    async evaluateOnNewDocument(script) {
        await this._updateBootstrapScript('main');
    }
    _bindingToScript(binding) {
        return `self.${binding.name} = (param) => console.debug('${BINDING_CALL_MESSAGE}', {}, param); ${binding.source}`;
    }
    _calculateBootstrapScript(world) {
        const scripts = [];
        for (const binding of this._page.allBindings()) {
            if (binding.world === world)
                scripts.push(this._bindingToScript(binding));
        }
        if (world === 'main') {
            scripts.push(...this._browserContext._evaluateOnNewDocumentSources);
            scripts.push(...this._page._evaluateOnNewDocumentSources);
        }
        return scripts.join(';');
    }
    async _updateBootstrapScript(world) {
        await this._updateState('Page.setBootstrapScript', { source: this._calculateBootstrapScript(world), worldName: webkitWorldName(world) });
    }
    async closePage(runBeforeUnload) {
        await this._stopVideo();
        await this._pageProxySession.sendMayFail('Target.close', {
            targetId: this._session.sessionId,
            runBeforeUnload
        });
    }
    canScreenshotOutsideViewport() {
        return true;
    }
    async setBackgroundColor(color) {
        await this._session.send('Page.setDefaultBackgroundColorOverride', { color });
    }
    _toolbarHeight() {
        var _a;
        if ((_a = this._page._browserContext._browser) === null || _a === void 0 ? void 0 : _a.options.headful)
            return registry_1.hostPlatform.startsWith('10.15') ? 55 : 59;
        return 0;
    }
    async _startVideo(options) {
        utils_1.assert(!this._recordingVideoFile);
        const START_VIDEO_PROTOCOL_COMMAND = registry_1.hostPlatform === 'mac10.14' ? 'Screencast.start' : 'Screencast.startVideo';
        const { screencastId } = await this._pageProxySession.send(START_VIDEO_PROTOCOL_COMMAND, {
            file: options.outputFile,
            width: options.width,
            height: options.height,
            toolbarHeight: this._toolbarHeight()
        });
        this._recordingVideoFile = options.outputFile;
        this._browserContext._browser._videoStarted(this._browserContext, screencastId, options.outputFile, this.pageOrError());
    }
    async _stopVideo() {
        if (!this._recordingVideoFile)
            return;
        const STOP_VIDEO_PROTOCOL_COMMAND = registry_1.hostPlatform === 'mac10.14' ? 'Screencast.stop' : 'Screencast.stopVideo';
        await this._pageProxySession.sendMayFail(STOP_VIDEO_PROTOCOL_COMMAND);
        this._recordingVideoFile = null;
    }
    async takeScreenshot(progress, format, documentRect, viewportRect, quality) {
        const rect = (documentRect || viewportRect);
        const result = await this._session.send('Page.snapshotRect', { ...rect, coordinateSystem: documentRect ? 'Page' : 'Viewport' });
        const prefix = 'data:image/png;base64,';
        let buffer = Buffer.from(result.dataURL.substr(prefix.length), 'base64');
        if (format === 'jpeg')
            buffer = jpeg.encode(png.PNG.sync.read(buffer), quality).data;
        return buffer;
    }
    async resetViewport() {
        utils_1.assert(false, 'Should not be called');
    }
    async getContentFrame(handle) {
        const nodeInfo = await this._session.send('DOM.describeNode', {
            objectId: handle._objectId
        });
        if (!nodeInfo.contentFrameId)
            return null;
        return this._page._frameManager.frame(nodeInfo.contentFrameId);
    }
    async getOwnerFrame(handle) {
        if (!handle._objectId)
            return null;
        const nodeInfo = await this._session.send('DOM.describeNode', {
            objectId: handle._objectId
        });
        return nodeInfo.ownerFrameId || null;
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
        return await this._session.send('DOM.scrollIntoViewIfNeeded', {
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
    async setScreencastOptions(options) {
        if (options) {
            const so = { ...options, toolbarHeight: this._toolbarHeight() };
            const { generation } = await this._pageProxySession.send('Screencast.startScreencast', so);
            this._screencastGeneration = generation;
        }
        else {
            await this._pageProxySession.send('Screencast.stopScreencast');
        }
    }
    _onScreencastFrame(event) {
        this._pageProxySession.send('Screencast.screencastFrameAck', { generation: this._screencastGeneration }).catch(e => debugLogger_1.debugLogger.log('error', e));
        const buffer = Buffer.from(event.data, 'base64');
        this._page.emit(page_1.Page.Events.ScreencastFrame, {
            buffer,
            width: event.deviceWidth,
            height: event.deviceHeight,
        });
    }
    rafCountForStablePosition() {
        return process.platform === 'win32' ? 5 : 1;
    }
    async getContentQuads(handle) {
        const result = await this._session.sendMayFail('DOM.getContentQuads', {
            objectId: handle._objectId
        });
        if (!result)
            return null;
        return result.quads.map(quad => [
            { x: quad[0], y: quad[1] },
            { x: quad[2], y: quad[3] },
            { x: quad[4], y: quad[5] },
            { x: quad[6], y: quad[7] }
        ]);
    }
    async setInputFiles(handle, files) {
        const objectId = handle._objectId;
        const protocolFiles = files.map(file => ({
            name: file.name,
            type: file.mimeType,
            data: file.buffer,
        }));
        await this._session.send('DOM.setInputFiles', { objectId, files: protocolFiles });
    }
    async adoptElementHandle(handle, to) {
        const result = await this._session.sendMayFail('DOM.resolveNode', {
            objectId: handle._objectId,
            executionContextId: to._delegate._contextId
        });
        if (!result || result.object.subtype === 'null')
            throw new Error(dom.kUnableToAdoptErrorMessage);
        return to.createHandle(result.object);
    }
    async getAccessibilityTree(needle) {
        return wkAccessibility_1.getAccessibilityTree(this._session, needle);
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
    _onRequestWillBeSent(session, event) {
        if (event.request.url.startsWith('data:'))
            return;
        let redirectedFrom = null;
        if (event.redirectResponse) {
            const request = this._requestIdToRequest.get(event.requestId);
            // If we connect late to the target, we could have missed the requestWillBeSent event.
            if (request) {
                this._handleRequestRedirect(request, event.redirectResponse, event.timestamp);
                redirectedFrom = request.request;
            }
        }
        const frame = redirectedFrom ? redirectedFrom.frame() : this._page._frameManager.frame(event.frameId);
        // sometimes we get stray network events for detached frames
        // TODO(einbinder) why?
        if (!frame)
            return;
        // TODO(einbinder) this will fail if we are an XHR document request
        const isNavigationRequest = event.type === 'Document';
        const documentId = isNavigationRequest ? event.loaderId : undefined;
        // We do not support intercepting redirects.
        const allowInterception = this._page._needsRequestInterception() && !redirectedFrom;
        const request = new wkInterceptableRequest_1.WKInterceptableRequest(session, allowInterception, frame, event, redirectedFrom, documentId);
        this._requestIdToRequest.set(event.requestId, request);
        this._page._frameManager.requestStarted(request.request);
    }
    _handleRequestRedirect(request, responsePayload, timestamp) {
        const response = request.createResponse(responsePayload);
        response._requestFinished(responsePayload.timing ? helper_1.helper.secondsToRoundishMillis(timestamp - request._timestamp) : -1, 'Response body is unavailable for redirect responses');
        this._requestIdToRequest.delete(request._requestId);
        this._page._frameManager.requestReceivedResponse(response);
        this._page._frameManager.requestFinished(request.request);
    }
    _onRequestIntercepted(event) {
        const request = this._requestIdToRequest.get(event.requestId);
        if (!request) {
            this._session.sendMayFail('Network.interceptRequestWithError', { errorType: 'Cancellation', requestId: event.requestId });
            return;
        }
        if (!request._allowInterception) {
            // Intercepted, although we do not intend to allow interception.
            // Just continue.
            this._session.sendMayFail('Network.interceptWithRequest', { requestId: request._requestId });
        }
        else {
            request._interceptedCallback();
        }
    }
    _onResponseReceived(event) {
        const request = this._requestIdToRequest.get(event.requestId);
        // FileUpload sends a response without a matching request.
        if (!request)
            return;
        const response = request.createResponse(event.response);
        if (event.response.requestHeaders && Object.keys(event.response.requestHeaders).length)
            request.request.updateWithRawHeaders(utils_1.headersObjectToArray(event.response.requestHeaders));
        this._page._frameManager.requestReceivedResponse(response);
        if (response.status() === 204) {
            this._onLoadingFailed({
                requestId: event.requestId,
                errorText: 'Aborted: 204 No Content',
                timestamp: event.timestamp
            });
        }
    }
    _onLoadingFinished(event) {
        const request = this._requestIdToRequest.get(event.requestId);
        // For certain requestIds we never receive requestWillBeSent event.
        // @see https://crbug.com/750469
        if (!request)
            return;
        // Under certain conditions we never get the Network.responseReceived
        // event from protocol. @see https://crbug.com/883475
        const response = request.request._existingResponse();
        if (response)
            response._requestFinished(helper_1.helper.secondsToRoundishMillis(event.timestamp - request._timestamp));
        this._requestIdToRequest.delete(request._requestId);
        this._page._frameManager.requestFinished(request.request);
    }
    _onLoadingFailed(event) {
        const request = this._requestIdToRequest.get(event.requestId);
        // For certain requestIds we never receive requestWillBeSent event.
        // @see https://crbug.com/750469
        if (!request)
            return;
        const response = request.request._existingResponse();
        if (response)
            response._requestFinished(helper_1.helper.secondsToRoundishMillis(event.timestamp - request._timestamp));
        this._requestIdToRequest.delete(request._requestId);
        request.request._setFailureText(event.errorText);
        this._page._frameManager.requestFailed(request.request, event.errorText.includes('cancelled'));
    }
    async _grantPermissions(origin, permissions) {
        const webPermissionToProtocol = new Map([
            ['geolocation', 'geolocation'],
        ]);
        const filtered = permissions.map(permission => {
            const protocolPermission = webPermissionToProtocol.get(permission);
            if (!protocolPermission)
                throw new Error('Unknown permission: ' + permission);
            return protocolPermission;
        });
        await this._pageProxySession.send('Emulation.grantPermissions', { origin, permissions: filtered });
    }
    async _clearPermissions() {
        await this._pageProxySession.send('Emulation.resetPermissions', {});
    }
}
exports.WKPage = WKPage;
function webkitWorldName(world) {
    switch (world) {
        case 'main': return undefined;
        case 'utility': return UTILITY_WORLD_NAME;
    }
}
//# sourceMappingURL=wkPage.js.map