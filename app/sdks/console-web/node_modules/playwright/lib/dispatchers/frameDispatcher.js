"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
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
exports.FrameDispatcher = void 0;
const frames_1 = require("../server/frames");
const dispatcher_1 = require("./dispatcher");
const elementHandlerDispatcher_1 = require("./elementHandlerDispatcher");
const jsHandleDispatcher_1 = require("./jsHandleDispatcher");
const networkDispatchers_1 = require("./networkDispatchers");
class FrameDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, frame) {
        super(scope, frame, 'Frame', {
            url: frame.url(),
            name: frame.name(),
            parentFrame: dispatcher_1.lookupNullableDispatcher(frame.parentFrame()),
            loadStates: Array.from(frame._subtreeLifecycleEvents),
        });
        this._frame = frame;
        frame.on(frames_1.Frame.Events.AddLifecycle, lifecycleEvent => {
            this._dispatchEvent('loadstate', { add: lifecycleEvent });
        });
        frame.on(frames_1.Frame.Events.RemoveLifecycle, lifecycleEvent => {
            this._dispatchEvent('loadstate', { remove: lifecycleEvent });
        });
        frame.on(frames_1.Frame.Events.Navigation, (event) => {
            const params = { url: event.url, name: event.name, error: event.error ? event.error.message : undefined };
            if (event.newDocument)
                params.newDocument = { request: networkDispatchers_1.RequestDispatcher.fromNullable(this._scope, event.newDocument.request || null) };
            this._dispatchEvent('navigated', params);
        });
    }
    static from(scope, frame) {
        const result = dispatcher_1.existingDispatcher(frame);
        return result || new FrameDispatcher(scope, frame);
    }
    async goto(params, metadata) {
        return { response: dispatcher_1.lookupNullableDispatcher(await this._frame.goto(metadata, params.url, params)) };
    }
    async frameElement() {
        return { element: elementHandlerDispatcher_1.ElementHandleDispatcher.from(this._scope, await this._frame.frameElement()) };
    }
    async evaluateExpression(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._frame.evaluateExpressionAndWaitForSignals(params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg), 'main')) };
    }
    async evaluateExpressionHandle(params, metadata) {
        return { handle: elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(this._scope, await this._frame.evaluateExpressionHandleAndWaitForSignals(params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg), 'main')) };
    }
    async waitForSelector(params, metadata) {
        return { element: elementHandlerDispatcher_1.ElementHandleDispatcher.fromNullable(this._scope, await this._frame.waitForSelector(metadata, params.selector, params)) };
    }
    async dispatchEvent(params, metadata) {
        return this._frame.dispatchEvent(metadata, params.selector, params.type, jsHandleDispatcher_1.parseArgument(params.eventInit), params);
    }
    async evalOnSelector(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._frame.evalOnSelectorAndWaitForSignals(params.selector, params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async evalOnSelectorAll(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._frame.evalOnSelectorAllAndWaitForSignals(params.selector, params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async querySelector(params, metadata) {
        return { element: elementHandlerDispatcher_1.ElementHandleDispatcher.fromNullable(this._scope, await this._frame.$(params.selector)) };
    }
    async querySelectorAll(params, metadata) {
        const elements = await this._frame.$$(params.selector);
        return { elements: elements.map(e => elementHandlerDispatcher_1.ElementHandleDispatcher.from(this._scope, e)) };
    }
    async content() {
        return { value: await this._frame.content() };
    }
    async setContent(params, metadata) {
        return await this._frame.setContent(metadata, params.html, params);
    }
    async addScriptTag(params, metadata) {
        return { element: elementHandlerDispatcher_1.ElementHandleDispatcher.from(this._scope, await this._frame.addScriptTag(params)) };
    }
    async addStyleTag(params, metadata) {
        return { element: elementHandlerDispatcher_1.ElementHandleDispatcher.from(this._scope, await this._frame.addStyleTag(params)) };
    }
    async click(params, metadata) {
        return await this._frame.click(metadata, params.selector, params);
    }
    async dblclick(params, metadata) {
        return await this._frame.dblclick(metadata, params.selector, params);
    }
    async tap(params, metadata) {
        return await this._frame.tap(metadata, params.selector, params);
    }
    async fill(params, metadata) {
        return await this._frame.fill(metadata, params.selector, params.value, params);
    }
    async focus(params, metadata) {
        await this._frame.focus(metadata, params.selector, params);
    }
    async textContent(params, metadata) {
        const value = await this._frame.textContent(metadata, params.selector, params);
        return { value: value === null ? undefined : value };
    }
    async innerText(params, metadata) {
        return { value: await this._frame.innerText(metadata, params.selector, params) };
    }
    async innerHTML(params, metadata) {
        return { value: await this._frame.innerHTML(metadata, params.selector, params) };
    }
    async getAttribute(params, metadata) {
        const value = await this._frame.getAttribute(metadata, params.selector, params.name, params);
        return { value: value === null ? undefined : value };
    }
    async isChecked(params, metadata) {
        return { value: await this._frame.isChecked(metadata, params.selector, params) };
    }
    async isDisabled(params, metadata) {
        return { value: await this._frame.isDisabled(metadata, params.selector, params) };
    }
    async isEditable(params, metadata) {
        return { value: await this._frame.isEditable(metadata, params.selector, params) };
    }
    async isEnabled(params, metadata) {
        return { value: await this._frame.isEnabled(metadata, params.selector, params) };
    }
    async isHidden(params, metadata) {
        return { value: await this._frame.isHidden(metadata, params.selector, params) };
    }
    async isVisible(params, metadata) {
        return { value: await this._frame.isVisible(metadata, params.selector, params) };
    }
    async hover(params, metadata) {
        return await this._frame.hover(metadata, params.selector, params);
    }
    async selectOption(params, metadata) {
        const elements = (params.elements || []).map(e => e._elementHandle);
        return { values: await this._frame.selectOption(metadata, params.selector, elements, params.options || [], params) };
    }
    async setInputFiles(params, metadata) {
        return await this._frame.setInputFiles(metadata, params.selector, params.files, params);
    }
    async type(params, metadata) {
        return await this._frame.type(metadata, params.selector, params.text, params);
    }
    async press(params, metadata) {
        return await this._frame.press(metadata, params.selector, params.key, params);
    }
    async check(params, metadata) {
        return await this._frame.check(metadata, params.selector, params);
    }
    async uncheck(params, metadata) {
        return await this._frame.uncheck(metadata, params.selector, params);
    }
    async waitForFunction(params, metadata) {
        return { handle: elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(this._scope, await this._frame._waitForFunctionExpression(metadata, params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg), params)) };
    }
    async title(params, metadata) {
        return { value: await this._frame.title() };
    }
}
exports.FrameDispatcher = FrameDispatcher;
//# sourceMappingURL=frameDispatcher.js.map