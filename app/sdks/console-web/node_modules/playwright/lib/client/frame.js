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
exports.verifyLoadState = exports.Frame = void 0;
const utils_1 = require("../utils/utils");
const channelOwner_1 = require("./channelOwner");
const elementHandle_1 = require("./elementHandle");
const jsHandle_1 = require("./jsHandle");
const fs_1 = __importDefault(require("fs"));
const network = __importStar(require("./network"));
const events_1 = require("events");
const waiter_1 = require("./waiter");
const events_2 = require("./events");
const types_1 = require("./types");
const clientHelper_1 = require("./clientHelper");
class Frame extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._parentFrame = null;
        this._url = '';
        this._name = '';
        this._detached = false;
        this._childFrames = new Set();
        this._eventEmitter = new events_1.EventEmitter();
        this._eventEmitter.setMaxListeners(0);
        this._parentFrame = Frame.fromNullable(initializer.parentFrame);
        if (this._parentFrame)
            this._parentFrame._childFrames.add(this);
        this._name = initializer.name;
        this._url = initializer.url;
        this._loadStates = new Set(initializer.loadStates);
        this._channel.on('loadstate', event => {
            if (event.add) {
                this._loadStates.add(event.add);
                this._eventEmitter.emit('loadstate', event.add);
            }
            if (event.remove)
                this._loadStates.delete(event.remove);
        });
        this._channel.on('navigated', event => {
            this._url = event.url;
            this._name = event.name;
            this._eventEmitter.emit('navigated', event);
            if (!event.error && this._page)
                this._page.emit(events_2.Events.Page.FrameNavigated, this);
        });
    }
    static from(frame) {
        return frame._object;
    }
    static fromNullable(frame) {
        return frame ? Frame.from(frame) : null;
    }
    _apiName(method) {
        return this._page._isPageCall ? 'page.' + method : 'frame.' + method;
    }
    page() {
        return this._page;
    }
    async goto(url, options = {}) {
        return this._wrapApiCall(this._apiName('goto'), async (channel) => {
            const waitUntil = verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            return network.Response.fromNullable((await channel.goto({ url, ...options, waitUntil })).response);
        });
    }
    _setupNavigationWaiter(name, options) {
        const waiter = new waiter_1.Waiter(this, name);
        if (this._page.isClosed())
            waiter.rejectImmediately(new Error('Navigation failed because page was closed!'));
        waiter.rejectOnEvent(this._page, events_2.Events.Page.Close, new Error('Navigation failed because page was closed!'));
        waiter.rejectOnEvent(this._page, events_2.Events.Page.Crash, new Error('Navigation failed because page crashed!'));
        waiter.rejectOnEvent(this._page, events_2.Events.Page.FrameDetached, new Error('Navigating frame was detached!'), frame => frame === this);
        const timeout = this._page._timeoutSettings.navigationTimeout(options);
        waiter.rejectOnTimeout(timeout, `Timeout ${timeout}ms exceeded.`);
        return waiter;
    }
    async waitForNavigation(options = {}) {
        return this._wrapApiCall(this._apiName('waitForNavigation'), async (channel) => {
            const waitUntil = verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            const waiter = this._setupNavigationWaiter(this._apiName('waitForNavigation'), options);
            const toUrl = typeof options.url === 'string' ? ` to "${options.url}"` : '';
            waiter.log(`waiting for navigation${toUrl} until "${waitUntil}"`);
            const navigatedEvent = await waiter.waitForEvent(this._eventEmitter, 'navigated', event => {
                // Any failed navigation results in a rejection.
                if (event.error)
                    return true;
                waiter.log(`  navigated to "${event.url}"`);
                return clientHelper_1.urlMatches(event.url, options.url);
            });
            if (navigatedEvent.error) {
                const e = new Error(navigatedEvent.error);
                e.stack = '';
                await waiter.waitForPromise(Promise.reject(e));
            }
            if (!this._loadStates.has(waitUntil)) {
                await waiter.waitForEvent(this._eventEmitter, 'loadstate', s => {
                    waiter.log(`  "${s}" event fired`);
                    return s === waitUntil;
                });
            }
            const request = navigatedEvent.newDocument ? network.Request.fromNullable(navigatedEvent.newDocument.request) : null;
            const response = request ? await waiter.waitForPromise(request._finalRequest().response()) : null;
            waiter.dispose();
            return response;
        });
    }
    async waitForLoadState(state = 'load', options = {}) {
        state = verifyLoadState('state', state);
        if (this._loadStates.has(state))
            return;
        return this._wrapApiCall(this._apiName('waitForLoadState'), async (channel) => {
            const waiter = this._setupNavigationWaiter(this._apiName('waitForLoadState'), options);
            await waiter.waitForEvent(this._eventEmitter, 'loadstate', s => {
                waiter.log(`  "${s}" event fired`);
                return s === state;
            });
            waiter.dispose();
        });
    }
    async waitForURL(url, options = {}) {
        if (clientHelper_1.urlMatches(this.url(), url))
            return await this.waitForLoadState(options === null || options === void 0 ? void 0 : options.waitUntil, options);
        await this.waitForNavigation({ url, ...options });
    }
    async frameElement() {
        return this._wrapApiCall(this._apiName('frameElement'), async (channel) => {
            return elementHandle_1.ElementHandle.from((await channel.frameElement()).element);
        });
    }
    async evaluateHandle(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._wrapApiCall(this._apiName('evaluateHandle'), async (channel) => {
            const result = await channel.evaluateExpressionHandle({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.JSHandle.from(result.handle);
        });
    }
    async evaluate(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._wrapApiCall(this._apiName('evaluate'), async (channel) => {
            const result = await channel.evaluateExpression({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async $(selector) {
        return this._wrapApiCall(this._apiName('$'), async (channel) => {
            const result = await channel.querySelector({ selector });
            return elementHandle_1.ElementHandle.fromNullable(result.element);
        });
    }
    async waitForSelector(selector, options = {}) {
        return this._wrapApiCall(this._apiName('waitForSelector'), async (channel) => {
            if (options.visibility)
                throw new Error('options.visibility is not supported, did you mean options.state?');
            if (options.waitFor && options.waitFor !== 'visible')
                throw new Error('options.waitFor is not supported, did you mean options.state?');
            const result = await channel.waitForSelector({ selector, ...options });
            return elementHandle_1.ElementHandle.fromNullable(result.element);
        });
    }
    async dispatchEvent(selector, type, eventInit, options = {}) {
        return this._wrapApiCall(this._apiName('dispatchEvent'), async (channel) => {
            await channel.dispatchEvent({ selector, type, eventInit: jsHandle_1.serializeArgument(eventInit), ...options });
        });
    }
    async $eval(selector, pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 3);
        return this._wrapApiCall(this._apiName('$eval'), async (channel) => {
            const result = await channel.evalOnSelector({ selector, expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async $$eval(selector, pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 3);
        return this._wrapApiCall(this._apiName('$$eval'), async (channel) => {
            const result = await channel.evalOnSelectorAll({ selector, expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async $$(selector) {
        return this._wrapApiCall(this._apiName('$$'), async (channel) => {
            const result = await channel.querySelectorAll({ selector });
            return result.elements.map(e => elementHandle_1.ElementHandle.from(e));
        });
    }
    async content() {
        return this._wrapApiCall(this._apiName('content'), async (channel) => {
            return (await channel.content()).value;
        });
    }
    async setContent(html, options = {}) {
        return this._wrapApiCall(this._apiName('setContent'), async (channel) => {
            const waitUntil = verifyLoadState('waitUntil', options.waitUntil === undefined ? 'load' : options.waitUntil);
            await channel.setContent({ html, ...options, waitUntil });
        });
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
    isDetached() {
        return this._detached;
    }
    async addScriptTag(options = {}) {
        return this._wrapApiCall(this._apiName('addScriptTag'), async (channel) => {
            const copy = { ...options };
            if (copy.path) {
                copy.content = (await fs_1.default.promises.readFile(copy.path)).toString();
                copy.content += '//# sourceURL=' + copy.path.replace(/\n/g, '');
            }
            return elementHandle_1.ElementHandle.from((await channel.addScriptTag({ ...copy })).element);
        });
    }
    async addStyleTag(options = {}) {
        return this._wrapApiCall(this._apiName('addStyleTag'), async (channel) => {
            const copy = { ...options };
            if (copy.path) {
                copy.content = (await fs_1.default.promises.readFile(copy.path)).toString();
                copy.content += '/*# sourceURL=' + copy.path.replace(/\n/g, '') + '*/';
            }
            return elementHandle_1.ElementHandle.from((await channel.addStyleTag({ ...copy })).element);
        });
    }
    async click(selector, options = {}) {
        return this._wrapApiCall(this._apiName('click'), async (channel) => {
            return await channel.click({ selector, ...options });
        });
    }
    async dblclick(selector, options = {}) {
        return this._wrapApiCall(this._apiName('dblclick'), async (channel) => {
            return await channel.dblclick({ selector, ...options });
        });
    }
    async tap(selector, options = {}) {
        return this._wrapApiCall(this._apiName('tap'), async (channel) => {
            return await channel.tap({ selector, ...options });
        });
    }
    async fill(selector, value, options = {}) {
        return this._wrapApiCall(this._apiName('fill'), async (channel) => {
            return await channel.fill({ selector, value, ...options });
        });
    }
    async focus(selector, options = {}) {
        return this._wrapApiCall(this._apiName('focus'), async (channel) => {
            await channel.focus({ selector, ...options });
        });
    }
    async textContent(selector, options = {}) {
        return this._wrapApiCall(this._apiName('textContent'), async (channel) => {
            const value = (await channel.textContent({ selector, ...options })).value;
            return value === undefined ? null : value;
        });
    }
    async innerText(selector, options = {}) {
        return this._wrapApiCall(this._apiName('innerText'), async (channel) => {
            return (await channel.innerText({ selector, ...options })).value;
        });
    }
    async innerHTML(selector, options = {}) {
        return this._wrapApiCall(this._apiName('innerHTML'), async (channel) => {
            return (await channel.innerHTML({ selector, ...options })).value;
        });
    }
    async getAttribute(selector, name, options = {}) {
        return this._wrapApiCall(this._apiName('getAttribute'), async (channel) => {
            const value = (await channel.getAttribute({ selector, name, ...options })).value;
            return value === undefined ? null : value;
        });
    }
    async isChecked(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isChecked'), async (channel) => {
            return (await channel.isChecked({ selector, ...options })).value;
        });
    }
    async isDisabled(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isDisabled'), async (channel) => {
            return (await channel.isDisabled({ selector, ...options })).value;
        });
    }
    async isEditable(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isEditable'), async (channel) => {
            return (await channel.isEditable({ selector, ...options })).value;
        });
    }
    async isEnabled(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isEnabled'), async (channel) => {
            return (await channel.isEnabled({ selector, ...options })).value;
        });
    }
    async isHidden(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isHidden'), async (channel) => {
            return (await channel.isHidden({ selector, ...options })).value;
        });
    }
    async isVisible(selector, options = {}) {
        return this._wrapApiCall(this._apiName('isVisible'), async (channel) => {
            return (await channel.isVisible({ selector, ...options })).value;
        });
    }
    async hover(selector, options = {}) {
        return this._wrapApiCall(this._apiName('hover'), async (channel) => {
            await channel.hover({ selector, ...options });
        });
    }
    async selectOption(selector, values, options = {}) {
        return this._wrapApiCall(this._apiName('selectOption'), async (channel) => {
            return (await channel.selectOption({ selector, ...elementHandle_1.convertSelectOptionValues(values), ...options })).values;
        });
    }
    async setInputFiles(selector, files, options = {}) {
        return this._wrapApiCall(this._apiName('setInputFiles'), async (channel) => {
            await channel.setInputFiles({ selector, files: await elementHandle_1.convertInputFiles(files), ...options });
        });
    }
    async type(selector, text, options = {}) {
        return this._wrapApiCall(this._apiName('type'), async (channel) => {
            await channel.type({ selector, text, ...options });
        });
    }
    async press(selector, key, options = {}) {
        return this._wrapApiCall(this._apiName('press'), async (channel) => {
            await channel.press({ selector, key, ...options });
        });
    }
    async check(selector, options = {}) {
        return this._wrapApiCall(this._apiName('check'), async (channel) => {
            await channel.check({ selector, ...options });
        });
    }
    async uncheck(selector, options = {}) {
        return this._wrapApiCall(this._apiName('uncheck'), async (channel) => {
            await channel.uncheck({ selector, ...options });
        });
    }
    async waitForTimeout(timeout) {
        return this._wrapApiCall(this._apiName('waitForTimeout'), async (channel) => {
            await new Promise(fulfill => setTimeout(fulfill, timeout));
        });
    }
    async waitForFunction(pageFunction, arg, options = {}) {
        return this._wrapApiCall(this._apiName('waitForFunction'), async (channel) => {
            if (typeof options.polling === 'string')
                utils_1.assert(options.polling === 'raf', 'Unknown polling option: ' + options.polling);
            const result = await channel.waitForFunction({
                ...options,
                pollingInterval: options.polling === 'raf' ? undefined : options.polling,
                expression: String(pageFunction),
                isFunction: typeof pageFunction === 'function',
                arg: jsHandle_1.serializeArgument(arg),
            });
            return jsHandle_1.JSHandle.from(result.handle);
        });
    }
    async title() {
        return this._wrapApiCall(this._apiName('title'), async (channel) => {
            return (await channel.title()).value;
        });
    }
}
exports.Frame = Frame;
function verifyLoadState(name, waitUntil) {
    if (waitUntil === 'networkidle0')
        waitUntil = 'networkidle';
    if (!types_1.kLifecycleEvents.has(waitUntil))
        throw new Error(`${name}: expected one of (load|domcontentloaded|networkidle)`);
    return waitUntil;
}
exports.verifyLoadState = verifyLoadState;
//# sourceMappingURL=frame.js.map