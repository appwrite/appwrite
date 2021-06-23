"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an 'AS IS' BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.BindingCallDispatcher = exports.WorkerDispatcher = exports.PageDispatcher = void 0;
const page_1 = require("../server/page");
const dispatcher_1 = require("./dispatcher");
const serializers_1 = require("../protocol/serializers");
const consoleMessageDispatcher_1 = require("./consoleMessageDispatcher");
const dialogDispatcher_1 = require("./dialogDispatcher");
const frameDispatcher_1 = require("./frameDispatcher");
const networkDispatchers_1 = require("./networkDispatchers");
const jsHandleDispatcher_1 = require("./jsHandleDispatcher");
const elementHandlerDispatcher_1 = require("./elementHandlerDispatcher");
const artifactDispatcher_1 = require("./artifactDispatcher");
const utils_1 = require("../utils/utils");
class PageDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, page) {
        // TODO: theoretically, there could be more than one frame already.
        // If we split pageCreated and pageReady, there should be no main frame during pageCreated.
        super(scope, page, 'Page', {
            mainFrame: frameDispatcher_1.FrameDispatcher.from(scope, page.mainFrame()),
            viewportSize: page.viewportSize() || undefined,
            isClosed: page.isClosed(),
            opener: PageDispatcher.fromNullable(scope, page.opener())
        }, true);
        this._page = page;
        page.on(page_1.Page.Events.Close, () => {
            this._dispatchEvent('close');
            this._dispose();
        });
        page.on(page_1.Page.Events.Console, message => this._dispatchEvent('console', { message: new consoleMessageDispatcher_1.ConsoleMessageDispatcher(this._scope, message) }));
        page.on(page_1.Page.Events.Crash, () => this._dispatchEvent('crash'));
        page.on(page_1.Page.Events.DOMContentLoaded, () => this._dispatchEvent('domcontentloaded'));
        page.on(page_1.Page.Events.Dialog, dialog => this._dispatchEvent('dialog', { dialog: new dialogDispatcher_1.DialogDispatcher(this._scope, dialog) }));
        page.on(page_1.Page.Events.Download, (download) => {
            this._dispatchEvent('download', { url: download.url, suggestedFilename: download.suggestedFilename(), artifact: new artifactDispatcher_1.ArtifactDispatcher(scope, download.artifact) });
        });
        this._page.on(page_1.Page.Events.FileChooser, (fileChooser) => this._dispatchEvent('fileChooser', {
            element: elementHandlerDispatcher_1.ElementHandleDispatcher.from(this._scope, fileChooser.element()),
            isMultiple: fileChooser.isMultiple()
        }));
        page.on(page_1.Page.Events.FrameAttached, frame => this._onFrameAttached(frame));
        page.on(page_1.Page.Events.FrameDetached, frame => this._onFrameDetached(frame));
        page.on(page_1.Page.Events.Load, () => this._dispatchEvent('load'));
        page.on(page_1.Page.Events.PageError, error => this._dispatchEvent('pageError', { error: serializers_1.serializeError(error) }));
        page.on(page_1.Page.Events.WebSocket, webSocket => this._dispatchEvent('webSocket', { webSocket: new networkDispatchers_1.WebSocketDispatcher(this._scope, webSocket) }));
        page.on(page_1.Page.Events.Worker, worker => this._dispatchEvent('worker', { worker: new WorkerDispatcher(this._scope, worker) }));
        page.on(page_1.Page.Events.Video, (artifact) => this._dispatchEvent('video', { artifact: dispatcher_1.existingDispatcher(artifact) }));
        if (page._video)
            this._dispatchEvent('video', { artifact: dispatcher_1.existingDispatcher(page._video) });
    }
    static fromNullable(scope, page) {
        if (!page)
            return undefined;
        const result = dispatcher_1.existingDispatcher(page);
        return result || new PageDispatcher(scope, page);
    }
    page() {
        return this._page;
    }
    async setDefaultNavigationTimeoutNoReply(params, metadata) {
        this._page.setDefaultNavigationTimeout(params.timeout);
    }
    async setDefaultTimeoutNoReply(params, metadata) {
        this._page.setDefaultTimeout(params.timeout);
    }
    async exposeBinding(params, metadata) {
        await this._page.exposeBinding(params.name, !!params.needsHandle, (source, ...args) => {
            const binding = new BindingCallDispatcher(this._scope, params.name, !!params.needsHandle, source, args);
            this._dispatchEvent('bindingCall', { binding });
            return binding.promise();
        });
    }
    async setExtraHTTPHeaders(params, metadata) {
        await this._page.setExtraHTTPHeaders(params.headers);
    }
    async reload(params, metadata) {
        return { response: dispatcher_1.lookupNullableDispatcher(await this._page.reload(metadata, params)) };
    }
    async goBack(params, metadata) {
        return { response: dispatcher_1.lookupNullableDispatcher(await this._page.goBack(metadata, params)) };
    }
    async goForward(params, metadata) {
        return { response: dispatcher_1.lookupNullableDispatcher(await this._page.goForward(metadata, params)) };
    }
    async emulateMedia(params, metadata) {
        await this._page.emulateMedia({
            media: params.media === 'null' ? null : params.media,
            colorScheme: params.colorScheme === 'null' ? null : params.colorScheme,
            reducedMotion: params.reducedMotion === 'null' ? null : params.reducedMotion,
        });
    }
    async setViewportSize(params, metadata) {
        await this._page.setViewportSize(params.viewportSize);
    }
    async addInitScript(params, metadata) {
        await this._page._addInitScriptExpression(params.source);
    }
    async setNetworkInterceptionEnabled(params, metadata) {
        if (!params.enabled) {
            await this._page._setClientRequestInterceptor(undefined);
            return;
        }
        await this._page._setClientRequestInterceptor((route, request) => {
            this._dispatchEvent('route', { route: networkDispatchers_1.RouteDispatcher.from(this._scope, route), request: networkDispatchers_1.RequestDispatcher.from(this._scope, request) });
        });
    }
    async screenshot(params, metadata) {
        return { binary: (await this._page.screenshot(metadata, params)).toString('base64') };
    }
    async close(params, metadata) {
        await this._page.close(metadata, params);
    }
    async setFileChooserInterceptedNoReply(params, metadata) {
        await this._page._setFileChooserIntercepted(params.intercepted);
    }
    async keyboardDown(params, metadata) {
        await this._page.keyboard.down(params.key);
    }
    async keyboardUp(params, metadata) {
        await this._page.keyboard.up(params.key);
    }
    async keyboardInsertText(params, metadata) {
        await this._page.keyboard.insertText(params.text);
    }
    async keyboardType(params, metadata) {
        await this._page.keyboard.type(params.text, params);
    }
    async keyboardPress(params, metadata) {
        await this._page.keyboard.press(params.key, params);
    }
    async mouseMove(params, metadata) {
        await this._page.mouse.move(params.x, params.y, params);
    }
    async mouseDown(params, metadata) {
        await this._page.mouse.down(params);
    }
    async mouseUp(params, metadata) {
        await this._page.mouse.up(params);
    }
    async mouseClick(params, metadata) {
        await this._page.mouse.click(params.x, params.y, params);
    }
    async touchscreenTap(params, metadata) {
        await this._page.touchscreen.tap(params.x, params.y);
    }
    async accessibilitySnapshot(params, metadata) {
        const rootAXNode = await this._page.accessibility.snapshot({
            interestingOnly: params.interestingOnly,
            root: params.root ? params.root._elementHandle : undefined
        });
        return { rootAXNode: rootAXNode || undefined };
    }
    async pdf(params, metadata) {
        if (!this._page.pdf)
            throw new Error('PDF generation is only supported for Headless Chromium');
        const buffer = await this._page.pdf(params);
        return { pdf: buffer.toString('base64') };
    }
    async bringToFront(params, metadata) {
        await this._page.bringToFront();
    }
    async startJSCoverage(params, metadata) {
        const coverage = this._page.coverage;
        await coverage.startJSCoverage(params);
    }
    async stopJSCoverage(params, metadata) {
        const coverage = this._page.coverage;
        return { entries: await coverage.stopJSCoverage() };
    }
    async startCSSCoverage(params, metadata) {
        const coverage = this._page.coverage;
        await coverage.startCSSCoverage(params);
    }
    async stopCSSCoverage(params, metadata) {
        const coverage = this._page.coverage;
        return { entries: await coverage.stopCSSCoverage() };
    }
    _onFrameAttached(frame) {
        this._dispatchEvent('frameAttached', { frame: frameDispatcher_1.FrameDispatcher.from(this._scope, frame) });
    }
    _onFrameDetached(frame) {
        this._dispatchEvent('frameDetached', { frame: dispatcher_1.lookupDispatcher(frame) });
    }
}
exports.PageDispatcher = PageDispatcher;
class WorkerDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, worker) {
        super(scope, worker, 'Worker', {
            url: worker.url()
        });
        worker.on(page_1.Worker.Events.Close, () => this._dispatchEvent('close'));
    }
    async evaluateExpression(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._object.evaluateExpression(params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async evaluateExpressionHandle(params, metadata) {
        return { handle: elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(this._scope, await this._object.evaluateExpressionHandle(params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
}
exports.WorkerDispatcher = WorkerDispatcher;
class BindingCallDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, name, needsHandle, source, args) {
        super(scope, { guid: utils_1.createGuid() }, 'BindingCall', {
            frame: dispatcher_1.lookupDispatcher(source.frame),
            name,
            args: needsHandle ? undefined : args.map(jsHandleDispatcher_1.serializeResult),
            handle: needsHandle ? elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(scope, args[0]) : undefined,
        });
        this._promise = new Promise((resolve, reject) => {
            this._resolve = resolve;
            this._reject = reject;
        });
    }
    promise() {
        return this._promise;
    }
    async resolve(params, metadata) {
        this._resolve(jsHandleDispatcher_1.parseArgument(params.result));
    }
    async reject(params, metadata) {
        this._reject(serializers_1.parseError(params.error));
    }
}
exports.BindingCallDispatcher = BindingCallDispatcher;
//# sourceMappingURL=pageDispatcher.js.map