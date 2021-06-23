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
exports.ElectronApplication = exports.Electron = void 0;
const timeoutSettings_1 = require("../utils/timeoutSettings");
const utils_1 = require("../utils/utils");
const browserContext_1 = require("./browserContext");
const channelOwner_1 = require("./channelOwner");
const clientHelper_1 = require("./clientHelper");
const events_1 = require("./events");
const jsHandle_1 = require("./jsHandle");
const waiter_1 = require("./waiter");
class Electron extends channelOwner_1.ChannelOwner {
    static from(electron) {
        return electron._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
    }
    async launch(options = {}) {
        return this._wrapApiCall('electron.launch', async (channel) => {
            const params = {
                sdkLanguage: 'javascript',
                ...options,
                extraHTTPHeaders: options.extraHTTPHeaders && utils_1.headersObjectToArray(options.extraHTTPHeaders),
                env: clientHelper_1.envObjectToArray(options.env ? options.env : process.env),
            };
            return ElectronApplication.from((await channel.launch(params)).electronApplication);
        });
    }
}
exports.Electron = Electron;
class ElectronApplication extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._windows = new Set();
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
        this._context = browserContext_1.BrowserContext.from(initializer.context);
        for (const page of this._context._pages)
            this._onPage(page);
        this._context.on(events_1.Events.BrowserContext.Page, page => this._onPage(page));
        this._channel.on('close', () => this.emit(events_1.Events.ElectronApplication.Close));
    }
    static from(electronApplication) {
        return electronApplication._object;
    }
    _onPage(page) {
        this._windows.add(page);
        this.emit(events_1.Events.ElectronApplication.Window, page);
        page.once(events_1.Events.Page.Close, () => this._windows.delete(page));
    }
    windows() {
        // TODO: add ElectronPage class inherting from Page.
        return [...this._windows];
    }
    async firstWindow() {
        return this._wrapApiCall('electronApplication.firstWindow', async (channel) => {
            if (this._windows.size)
                return this._windows.values().next().value;
            return this.waitForEvent('window');
        });
    }
    context() {
        return this._context;
    }
    async close() {
        await this._channel.close();
    }
    async waitForEvent(event, optionsOrPredicate = {}) {
        const timeout = this._timeoutSettings.timeout(typeof optionsOrPredicate === 'function' ? {} : optionsOrPredicate);
        const predicate = typeof optionsOrPredicate === 'function' ? optionsOrPredicate : optionsOrPredicate.predicate;
        const waiter = waiter_1.Waiter.createForEvent(this, 'electronApplication', event);
        waiter.rejectOnTimeout(timeout, `Timeout while waiting for event "${event}"`);
        if (event !== events_1.Events.ElectronApplication.Close)
            waiter.rejectOnEvent(this, events_1.Events.ElectronApplication.Close, new Error('Electron application closed'));
        const result = await waiter.waitForEvent(this, event, predicate);
        waiter.dispose();
        return result;
    }
    async browserWindow(page) {
        return this._wrapApiCall('electronApplication.browserWindow', async (channel) => {
            const result = await channel.browserWindow({ page: page._channel });
            return jsHandle_1.JSHandle.from(result.handle);
        });
    }
    async evaluate(pageFunction, arg) {
        return this._wrapApiCall('electronApplication.evaluate', async (channel) => {
            const result = await channel.evaluateExpression({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async evaluateHandle(pageFunction, arg) {
        return this._wrapApiCall('electronApplication.evaluateHandle', async (channel) => {
            const result = await channel.evaluateExpressionHandle({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.JSHandle.from(result.handle);
        });
    }
}
exports.ElectronApplication = ElectronApplication;
//# sourceMappingURL=electron.js.map