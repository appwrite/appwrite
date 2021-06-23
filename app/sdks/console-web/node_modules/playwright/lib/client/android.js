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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.AndroidWebView = exports.AndroidInput = exports.AndroidSocket = exports.AndroidDevice = exports.Android = void 0;
const fs_1 = __importDefault(require("fs"));
const utils_1 = require("../utils/utils");
const events_1 = require("./events");
const browserContext_1 = require("./browserContext");
const channelOwner_1 = require("./channelOwner");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const waiter_1 = require("./waiter");
const events_2 = require("events");
class Android extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
    }
    static from(android) {
        return android._object;
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
        this._channel.setDefaultTimeoutNoReply({ timeout });
    }
    async devices() {
        return this._wrapApiCall('android.devices', async (channel) => {
            const { devices } = await channel.devices();
            return devices.map(d => AndroidDevice.from(d));
        });
    }
}
exports.Android = Android;
class AndroidDevice extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._webViews = new Map();
        this.input = new AndroidInput(this);
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings(parent._timeoutSettings);
        this._channel.on('webViewAdded', ({ webView }) => this._onWebViewAdded(webView));
        this._channel.on('webViewRemoved', ({ pid }) => this._onWebViewRemoved(pid));
    }
    static from(androidDevice) {
        return androidDevice._object;
    }
    _onWebViewAdded(webView) {
        const view = new AndroidWebView(this, webView);
        this._webViews.set(webView.pid, view);
        this.emit(events_1.Events.AndroidDevice.WebView, view);
    }
    _onWebViewRemoved(pid) {
        const view = this._webViews.get(pid);
        this._webViews.delete(pid);
        if (view)
            view.emit(events_1.Events.AndroidWebView.Close);
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
        this._channel.setDefaultTimeoutNoReply({ timeout });
    }
    serial() {
        return this._initializer.serial;
    }
    model() {
        return this._initializer.model;
    }
    webViews() {
        return [...this._webViews.values()];
    }
    async webView(selector, options) {
        const webView = [...this._webViews.values()].find(v => v.pkg() === selector.pkg);
        if (webView)
            return webView;
        return this.waitForEvent('webview', {
            ...options,
            predicate: (view) => view.pkg() === selector.pkg
        });
    }
    async wait(selector, options) {
        await this._wrapApiCall('androidDevice.wait', async (channel) => {
            await channel.wait({ selector: toSelectorChannel(selector), ...options });
        });
    }
    async fill(selector, text, options) {
        await this._wrapApiCall('androidDevice.fill', async (channel) => {
            await channel.fill({ selector: toSelectorChannel(selector), text, ...options });
        });
    }
    async press(selector, key, options) {
        await this.tap(selector, options);
        await this.input.press(key);
    }
    async tap(selector, options) {
        await this._wrapApiCall('androidDevice.tap', async (channel) => {
            await channel.tap({ selector: toSelectorChannel(selector), ...options });
        });
    }
    async drag(selector, dest, options) {
        await this._wrapApiCall('androidDevice.drag', async (channel) => {
            await channel.drag({ selector: toSelectorChannel(selector), dest, ...options });
        });
    }
    async fling(selector, direction, options) {
        await this._wrapApiCall('androidDevice.fling', async (channel) => {
            await channel.fling({ selector: toSelectorChannel(selector), direction, ...options });
        });
    }
    async longTap(selector, options) {
        await this._wrapApiCall('androidDevice.longTap', async (channel) => {
            await channel.longTap({ selector: toSelectorChannel(selector), ...options });
        });
    }
    async pinchClose(selector, percent, options) {
        await this._wrapApiCall('androidDevice.pinchClose', async (channel) => {
            await channel.pinchClose({ selector: toSelectorChannel(selector), percent, ...options });
        });
    }
    async pinchOpen(selector, percent, options) {
        await this._wrapApiCall('androidDevice.pinchOpen', async (channel) => {
            await channel.pinchOpen({ selector: toSelectorChannel(selector), percent, ...options });
        });
    }
    async scroll(selector, direction, percent, options) {
        await this._wrapApiCall('androidDevice.scroll', async (channel) => {
            await channel.scroll({ selector: toSelectorChannel(selector), direction, percent, ...options });
        });
    }
    async swipe(selector, direction, percent, options) {
        await this._wrapApiCall('androidDevice.swipe', async (channel) => {
            await channel.swipe({ selector: toSelectorChannel(selector), direction, percent, ...options });
        });
    }
    async info(selector) {
        return await this._wrapApiCall('androidDevice.info', async (channel) => {
            return (await channel.info({ selector: toSelectorChannel(selector) })).info;
        });
    }
    async screenshot(options = {}) {
        return await this._wrapApiCall('androidDevice.screenshot', async (channel) => {
            const { binary } = await channel.screenshot();
            const buffer = Buffer.from(binary, 'base64');
            if (options.path)
                await fs_1.default.promises.writeFile(options.path, buffer);
            return buffer;
        });
    }
    async close() {
        return this._wrapApiCall('androidDevice.close', async (channel) => {
            await channel.close();
            this.emit(events_1.Events.AndroidDevice.Close);
        });
    }
    async shell(command) {
        return this._wrapApiCall('androidDevice.shell', async (channel) => {
            const { result } = await channel.shell({ command });
            return Buffer.from(result, 'base64');
        });
    }
    async open(command) {
        return this._wrapApiCall('androidDevice.open', async (channel) => {
            return AndroidSocket.from((await channel.open({ command })).socket);
        });
    }
    async installApk(file, options) {
        return this._wrapApiCall('androidDevice.installApk', async (channel) => {
            await channel.installApk({ file: await loadFile(file), args: options && options.args });
        });
    }
    async push(file, path, options) {
        return this._wrapApiCall('androidDevice.push', async (channel) => {
            await channel.push({ file: await loadFile(file), path, mode: options ? options.mode : undefined });
        });
    }
    async launchBrowser(options = {}) {
        return this._wrapApiCall('androidDevice.launchBrowser', async (channel) => {
            const contextOptions = await browserContext_1.prepareBrowserContextParams(options);
            const { context } = await channel.launchBrowser(contextOptions);
            return browserContext_1.BrowserContext.from(context);
        });
    }
    async waitForEvent(event, optionsOrPredicate = {}) {
        const timeout = this._timeoutSettings.timeout(typeof optionsOrPredicate === 'function' ? {} : optionsOrPredicate);
        const predicate = typeof optionsOrPredicate === 'function' ? optionsOrPredicate : optionsOrPredicate.predicate;
        const waiter = waiter_1.Waiter.createForEvent(this, 'androidDevice', event);
        waiter.rejectOnTimeout(timeout, `Timeout while waiting for event "${event}"`);
        if (event !== events_1.Events.AndroidDevice.Close)
            waiter.rejectOnEvent(this, events_1.Events.AndroidDevice.Close, new Error('Device closed'));
        const result = await waiter.waitForEvent(this, event, predicate);
        waiter.dispose();
        return result;
    }
}
exports.AndroidDevice = AndroidDevice;
class AndroidSocket extends channelOwner_1.ChannelOwner {
    static from(androidDevice) {
        return androidDevice._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._channel.on('data', ({ data }) => this.emit(events_1.Events.AndroidSocket.Data, Buffer.from(data, 'base64')));
        this._channel.on('close', () => this.emit(events_1.Events.AndroidSocket.Close));
    }
    async write(data) {
        return this._wrapApiCall('androidDevice.write', async (channel) => {
            await channel.write({ data: data.toString('base64') });
        });
    }
    async close() {
        return this._wrapApiCall('androidDevice.close', async (channel) => {
            await channel.close();
        });
    }
}
exports.AndroidSocket = AndroidSocket;
async function loadFile(file) {
    if (utils_1.isString(file))
        return fs_1.default.promises.readFile(file, { encoding: 'base64' }).toString();
    return file.toString('base64');
}
class AndroidInput {
    constructor(device) {
        this._device = device;
    }
    async type(text) {
        return this._device._wrapApiCall('androidDevice.inputType', async (channel) => {
            await channel.inputType({ text });
        });
    }
    async press(key) {
        return this._device._wrapApiCall('androidDevice.inputPress', async (channel) => {
            await channel.inputPress({ key });
        });
    }
    async tap(point) {
        return this._device._wrapApiCall('androidDevice.inputTap', async (channel) => {
            await channel.inputTap({ point });
        });
    }
    async swipe(from, segments, steps) {
        return this._device._wrapApiCall('androidDevice.inputSwipe', async (channel) => {
            await channel.inputSwipe({ segments, steps });
        });
    }
    async drag(from, to, steps) {
        return this._device._wrapApiCall('androidDevice.inputDragAndDrop', async (channel) => {
            await channel.inputDrag({ from, to, steps });
        });
    }
}
exports.AndroidInput = AndroidInput;
function toSelectorChannel(selector) {
    const { checkable, checked, clazz, clickable, depth, desc, enabled, focusable, focused, hasChild, hasDescendant, longClickable, pkg, res, scrollable, selected, text, } = selector;
    const toRegex = (value) => {
        if (value === undefined)
            return undefined;
        if (value instanceof RegExp)
            return value.source;
        return '^' + value.replace(/[|\\{}()[\]^$+*?.]/g, '\\$&').replace(/-/g, '\\x2d') + '$';
    };
    return {
        checkable,
        checked,
        clazz: toRegex(clazz),
        pkg: toRegex(pkg),
        desc: toRegex(desc),
        res: toRegex(res),
        text: toRegex(text),
        clickable,
        depth,
        enabled,
        focusable,
        focused,
        hasChild: hasChild ? { selector: toSelectorChannel(hasChild.selector) } : undefined,
        hasDescendant: hasDescendant ? { selector: toSelectorChannel(hasDescendant.selector), maxDepth: hasDescendant.maxDepth } : undefined,
        longClickable,
        scrollable,
        selected,
    };
}
class AndroidWebView extends events_2.EventEmitter {
    constructor(device, data) {
        super();
        this._device = device;
        this._data = data;
    }
    pid() {
        return this._data.pid;
    }
    pkg() {
        return this._data.pkg;
    }
    async page() {
        if (!this._pagePromise)
            this._pagePromise = this._fetchPage();
        return this._pagePromise;
    }
    async _fetchPage() {
        return this._device._wrapApiCall('androidWebView.page', async (channel) => {
            const { context } = await channel.connectToWebView({ pid: this._data.pid, sdkLanguage: 'javascript' });
            return browserContext_1.BrowserContext.from(context).pages()[0];
        });
    }
}
exports.AndroidWebView = AndroidWebView;
//# sourceMappingURL=android.js.map