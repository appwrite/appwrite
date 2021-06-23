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
exports.ElementHandleDispatcher = void 0;
const dispatcher_1 = require("./dispatcher");
const jsHandleDispatcher_1 = require("./jsHandleDispatcher");
class ElementHandleDispatcher extends jsHandleDispatcher_1.JSHandleDispatcher {
    constructor(scope, elementHandle) {
        super(scope, elementHandle);
        this._elementHandle = elementHandle;
    }
    static from(scope, handle) {
        return dispatcher_1.existingDispatcher(handle) || new ElementHandleDispatcher(scope, handle);
    }
    static fromNullable(scope, handle) {
        if (!handle)
            return undefined;
        return dispatcher_1.existingDispatcher(handle) || new ElementHandleDispatcher(scope, handle);
    }
    static fromJSHandle(scope, handle) {
        const result = dispatcher_1.existingDispatcher(handle);
        if (result)
            return result;
        return handle.asElement() ? new ElementHandleDispatcher(scope, handle.asElement()) : new jsHandleDispatcher_1.JSHandleDispatcher(scope, handle);
    }
    async ownerFrame(params, metadata) {
        return { frame: dispatcher_1.lookupNullableDispatcher(await this._elementHandle.ownerFrame()) };
    }
    async contentFrame(params, metadata) {
        return { frame: dispatcher_1.lookupNullableDispatcher(await this._elementHandle.contentFrame()) };
    }
    async getAttribute(params, metadata) {
        const value = await this._elementHandle.getAttribute(params.name);
        return { value: value === null ? undefined : value };
    }
    async textContent(params, metadata) {
        const value = await this._elementHandle.textContent();
        return { value: value === null ? undefined : value };
    }
    async innerText(params, metadata) {
        return { value: await this._elementHandle.innerText() };
    }
    async innerHTML(params, metadata) {
        return { value: await this._elementHandle.innerHTML() };
    }
    async isChecked(params, metadata) {
        return { value: await this._elementHandle.isChecked() };
    }
    async isDisabled(params, metadata) {
        return { value: await this._elementHandle.isDisabled() };
    }
    async isEditable(params, metadata) {
        return { value: await this._elementHandle.isEditable() };
    }
    async isEnabled(params, metadata) {
        return { value: await this._elementHandle.isEnabled() };
    }
    async isHidden(params, metadata) {
        return { value: await this._elementHandle.isHidden() };
    }
    async isVisible(params, metadata) {
        return { value: await this._elementHandle.isVisible() };
    }
    async dispatchEvent(params, metadata) {
        await this._elementHandle.dispatchEvent(params.type, jsHandleDispatcher_1.parseArgument(params.eventInit));
    }
    async scrollIntoViewIfNeeded(params, metadata) {
        await this._elementHandle.scrollIntoViewIfNeeded(metadata, params);
    }
    async hover(params, metadata) {
        return await this._elementHandle.hover(metadata, params);
    }
    async click(params, metadata) {
        return await this._elementHandle.click(metadata, params);
    }
    async dblclick(params, metadata) {
        return await this._elementHandle.dblclick(metadata, params);
    }
    async tap(params, metadata) {
        return await this._elementHandle.tap(metadata, params);
    }
    async selectOption(params, metadata) {
        const elements = (params.elements || []).map(e => e._elementHandle);
        return { values: await this._elementHandle.selectOption(metadata, elements, params.options || [], params) };
    }
    async fill(params, metadata) {
        return await this._elementHandle.fill(metadata, params.value, params);
    }
    async selectText(params, metadata) {
        await this._elementHandle.selectText(metadata, params);
    }
    async setInputFiles(params, metadata) {
        return await this._elementHandle.setInputFiles(metadata, params.files, params);
    }
    async focus(params, metadata) {
        await this._elementHandle.focus(metadata);
    }
    async type(params, metadata) {
        return await this._elementHandle.type(metadata, params.text, params);
    }
    async press(params, metadata) {
        return await this._elementHandle.press(metadata, params.key, params);
    }
    async check(params, metadata) {
        return await this._elementHandle.check(metadata, params);
    }
    async uncheck(params, metadata) {
        return await this._elementHandle.uncheck(metadata, params);
    }
    async boundingBox(params, metadata) {
        const value = await this._elementHandle.boundingBox();
        return { value: value || undefined };
    }
    async screenshot(params, metadata) {
        return { binary: (await this._elementHandle.screenshot(metadata, params)).toString('base64') };
    }
    async querySelector(params, metadata) {
        const handle = await this._elementHandle.$(params.selector);
        return { element: ElementHandleDispatcher.fromNullable(this._scope, handle) };
    }
    async querySelectorAll(params, metadata) {
        const elements = await this._elementHandle.$$(params.selector);
        return { elements: elements.map(e => ElementHandleDispatcher.from(this._scope, e)) };
    }
    async evalOnSelector(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._elementHandle.evalOnSelectorAndWaitForSignals(params.selector, params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async evalOnSelectorAll(params, metadata) {
        return { value: jsHandleDispatcher_1.serializeResult(await this._elementHandle.evalOnSelectorAllAndWaitForSignals(params.selector, params.expression, params.isFunction, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async waitForElementState(params, metadata) {
        await this._elementHandle.waitForElementState(metadata, params.state, params);
    }
    async waitForSelector(params, metadata) {
        return { element: ElementHandleDispatcher.fromNullable(this._scope, await this._elementHandle.waitForSelector(metadata, params.selector, params)) };
    }
}
exports.ElementHandleDispatcher = ElementHandleDispatcher;
//# sourceMappingURL=elementHandlerDispatcher.js.map