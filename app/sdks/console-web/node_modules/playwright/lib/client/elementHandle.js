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
exports.determineScreenshotType = exports.convertInputFiles = exports.convertSelectOptionValues = exports.ElementHandle = void 0;
const frame_1 = require("./frame");
const jsHandle_1 = require("./jsHandle");
const fs_1 = __importDefault(require("fs"));
const mime = __importStar(require("mime"));
const path_1 = __importDefault(require("path"));
const utils_1 = require("../utils/utils");
class ElementHandle extends jsHandle_1.JSHandle {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._elementChannel = this._channel;
    }
    static from(handle) {
        return handle._object;
    }
    static fromNullable(handle) {
        return handle ? ElementHandle.from(handle) : null;
    }
    asElement() {
        return this;
    }
    async ownerFrame() {
        return this._wrapApiCall('elementHandle.ownerFrame', async (channel) => {
            return frame_1.Frame.fromNullable((await channel.ownerFrame()).frame);
        });
    }
    async contentFrame() {
        return this._wrapApiCall('elementHandle.contentFrame', async (channel) => {
            return frame_1.Frame.fromNullable((await channel.contentFrame()).frame);
        });
    }
    async getAttribute(name) {
        return this._wrapApiCall('elementHandle.getAttribute', async (channel) => {
            const value = (await channel.getAttribute({ name })).value;
            return value === undefined ? null : value;
        });
    }
    async textContent() {
        return this._wrapApiCall('elementHandle.textContent', async (channel) => {
            const value = (await channel.textContent()).value;
            return value === undefined ? null : value;
        });
    }
    async innerText() {
        return this._wrapApiCall('elementHandle.innerText', async (channel) => {
            return (await channel.innerText()).value;
        });
    }
    async innerHTML() {
        return this._wrapApiCall('elementHandle.innerHTML', async (channel) => {
            return (await channel.innerHTML()).value;
        });
    }
    async isChecked() {
        return this._wrapApiCall('elementHandle.isChecked', async (channel) => {
            return (await channel.isChecked()).value;
        });
    }
    async isDisabled() {
        return this._wrapApiCall('elementHandle.isDisabled', async (channel) => {
            return (await channel.isDisabled()).value;
        });
    }
    async isEditable() {
        return this._wrapApiCall('elementHandle.isEditable', async (channel) => {
            return (await channel.isEditable()).value;
        });
    }
    async isEnabled() {
        return this._wrapApiCall('elementHandle.isEnabled', async (channel) => {
            return (await channel.isEnabled()).value;
        });
    }
    async isHidden() {
        return this._wrapApiCall('elementHandle.isHidden', async (channel) => {
            return (await channel.isHidden()).value;
        });
    }
    async isVisible() {
        return this._wrapApiCall('elementHandle.isVisible', async (channel) => {
            return (await channel.isVisible()).value;
        });
    }
    async dispatchEvent(type, eventInit = {}) {
        return this._wrapApiCall('elementHandle.dispatchEvent', async (channel) => {
            await channel.dispatchEvent({ type, eventInit: jsHandle_1.serializeArgument(eventInit) });
        });
    }
    async scrollIntoViewIfNeeded(options = {}) {
        return this._wrapApiCall('elementHandle.scrollIntoViewIfNeeded', async (channel) => {
            await channel.scrollIntoViewIfNeeded(options);
        });
    }
    async hover(options = {}) {
        return this._wrapApiCall('elementHandle.hover', async (channel) => {
            await channel.hover(options);
        });
    }
    async click(options = {}) {
        return this._wrapApiCall('elementHandle.click', async (channel) => {
            return await channel.click(options);
        });
    }
    async dblclick(options = {}) {
        return this._wrapApiCall('elementHandle.dblclick', async (channel) => {
            return await channel.dblclick(options);
        });
    }
    async tap(options = {}) {
        return this._wrapApiCall('elementHandle.tap', async (channel) => {
            return await channel.tap(options);
        });
    }
    async selectOption(values, options = {}) {
        return this._wrapApiCall('elementHandle.selectOption', async (channel) => {
            const result = await channel.selectOption({ ...convertSelectOptionValues(values), ...options });
            return result.values;
        });
    }
    async fill(value, options = {}) {
        return this._wrapApiCall('elementHandle.fill', async (channel) => {
            return await channel.fill({ value, ...options });
        });
    }
    async selectText(options = {}) {
        return this._wrapApiCall('elementHandle.selectText', async (channel) => {
            await channel.selectText(options);
        });
    }
    async setInputFiles(files, options = {}) {
        return this._wrapApiCall('elementHandle.setInputFiles', async (channel) => {
            await channel.setInputFiles({ files: await convertInputFiles(files), ...options });
        });
    }
    async focus() {
        return this._wrapApiCall('elementHandle.focus', async (channel) => {
            await channel.focus();
        });
    }
    async type(text, options = {}) {
        return this._wrapApiCall('elementHandle.type', async (channel) => {
            await channel.type({ text, ...options });
        });
    }
    async press(key, options = {}) {
        return this._wrapApiCall('elementHandle.press', async (channel) => {
            await channel.press({ key, ...options });
        });
    }
    async check(options = {}) {
        return this._wrapApiCall('elementHandle.check', async (channel) => {
            return await channel.check(options);
        });
    }
    async uncheck(options = {}) {
        return this._wrapApiCall('elementHandle.uncheck', async (channel) => {
            return await channel.uncheck(options);
        });
    }
    async boundingBox() {
        return this._wrapApiCall('elementHandle.boundingBox', async (channel) => {
            const value = (await channel.boundingBox()).value;
            return value === undefined ? null : value;
        });
    }
    async screenshot(options = {}) {
        return this._wrapApiCall('elementHandle.screenshot', async (channel) => {
            const copy = { ...options };
            if (!copy.type)
                copy.type = determineScreenshotType(options);
            const result = await channel.screenshot(copy);
            const buffer = Buffer.from(result.binary, 'base64');
            if (options.path) {
                await utils_1.mkdirIfNeeded(options.path);
                await fs_1.default.promises.writeFile(options.path, buffer);
            }
            return buffer;
        });
    }
    async $(selector) {
        return this._wrapApiCall('elementHandle.$', async (channel) => {
            return ElementHandle.fromNullable((await channel.querySelector({ selector })).element);
        });
    }
    async $$(selector) {
        return this._wrapApiCall('elementHandle.$$', async (channel) => {
            const result = await channel.querySelectorAll({ selector });
            return result.elements.map(h => ElementHandle.from(h));
        });
    }
    async $eval(selector, pageFunction, arg) {
        return this._wrapApiCall('elementHandle.$eval', async (channel) => {
            const result = await channel.evalOnSelector({ selector, expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async $$eval(selector, pageFunction, arg) {
        return this._wrapApiCall('elementHandle.$$eval', async (channel) => {
            const result = await channel.evalOnSelectorAll({ selector, expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async waitForElementState(state, options = {}) {
        return this._wrapApiCall('elementHandle.waitForElementState', async (channel) => {
            return await channel.waitForElementState({ state, ...options });
        });
    }
    async waitForSelector(selector, options = {}) {
        return this._wrapApiCall('elementHandle.waitForSelector', async (channel) => {
            const result = await channel.waitForSelector({ selector, ...options });
            return ElementHandle.fromNullable(result.element);
        });
    }
}
exports.ElementHandle = ElementHandle;
function convertSelectOptionValues(values) {
    if (values === null)
        return {};
    if (!Array.isArray(values))
        values = [values];
    if (!values.length)
        return {};
    for (let i = 0; i < values.length; i++)
        utils_1.assert(values[i] !== null, `options[${i}]: expected object, got null`);
    if (values[0] instanceof ElementHandle)
        return { elements: values.map((v) => v._elementChannel) };
    if (utils_1.isString(values[0]))
        return { options: values.map(value => ({ value })) };
    return { options: values };
}
exports.convertSelectOptionValues = convertSelectOptionValues;
async function convertInputFiles(files) {
    const items = Array.isArray(files) ? files : [files];
    const filePayloads = await Promise.all(items.map(async (item) => {
        if (typeof item === 'string') {
            return {
                name: path_1.default.basename(item),
                buffer: (await fs_1.default.promises.readFile(item)).toString('base64')
            };
        }
        else {
            return {
                name: item.name,
                mimeType: item.mimeType,
                buffer: item.buffer.toString('base64'),
            };
        }
    }));
    return filePayloads;
}
exports.convertInputFiles = convertInputFiles;
function determineScreenshotType(options) {
    if (options.path) {
        const mimeType = mime.getType(options.path);
        if (mimeType === 'image/png')
            return 'png';
        else if (mimeType === 'image/jpeg')
            return 'jpeg';
        throw new Error(`path: unsupported mime type "${mimeType}"`);
    }
    return options.type;
}
exports.determineScreenshotType = determineScreenshotType;
//# sourceMappingURL=elementHandle.js.map