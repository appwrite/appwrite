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
exports.PythonLanguageGenerator = void 0;
const language_1 = require("./language");
const recorderActions_1 = require("./recorderActions");
const utils_1 = require("./utils");
const deviceDescriptors_1 = __importDefault(require("../../deviceDescriptors"));
class PythonLanguageGenerator {
    constructor(isAsync) {
        this.id = 'python';
        this.fileName = 'Python';
        this.highlighter = 'python';
        this.id = isAsync ? 'python-async' : 'python';
        this.fileName = isAsync ? 'Python Async' : 'Python';
        this._isAsync = isAsync;
        this._awaitPrefix = isAsync ? 'await ' : '';
        this._asyncPrefix = isAsync ? 'async ' : '';
    }
    generateAction(actionInContext) {
        const { action, pageAlias } = actionInContext;
        const formatter = new PythonFormatter(4);
        formatter.newLine();
        formatter.add('# ' + recorderActions_1.actionTitle(action));
        if (action.name === 'openPage') {
            formatter.add(`${pageAlias} = ${this._awaitPrefix}context.new_page()`);
            if (action.url && action.url !== 'about:blank' && action.url !== 'chrome://newtab/')
                formatter.add(`${this._awaitPrefix}${pageAlias}.goto(${quote(action.url)})`);
            return formatter.format();
        }
        const subject = actionInContext.isMainFrame ? pageAlias :
            (actionInContext.frameName ?
                `${pageAlias}.frame(${formatOptions({ name: actionInContext.frameName }, false)})` :
                `${pageAlias}.frame(${formatOptions({ url: actionInContext.frameUrl }, false)})`);
        const signals = language_1.toSignalMap(action);
        if (signals.dialog)
            formatter.add(`  ${pageAlias}.once("dialog", lambda dialog: dialog.dismiss())`);
        const actionCall = this._generateActionCall(action);
        let code = `${this._awaitPrefix}${subject}.${actionCall}`;
        if (signals.popup) {
            code = `${this._asyncPrefix}with ${pageAlias}.expect_popup() as popup_info {
        ${code}
      }
      ${signals.popup.popupAlias} = ${this._awaitPrefix}popup_info.value`;
        }
        if (signals.download) {
            code = `${this._asyncPrefix}with ${pageAlias}.expect_download() as download_info {
        ${code}
      }
      download = ${this._awaitPrefix}download_info.value`;
        }
        if (signals.waitForNavigation) {
            code = `
      # ${this._asyncPrefix}with ${pageAlias}.expect_navigation(url=${quote(signals.waitForNavigation.url)}):
      ${this._asyncPrefix}with ${pageAlias}.expect_navigation() {
        ${code}
      }`;
        }
        formatter.add(code);
        if (signals.assertNavigation)
            formatter.add(`  # assert ${pageAlias}.url == ${quote(signals.assertNavigation.url)}`);
        return formatter.format();
    }
    _generateActionCall(action) {
        switch (action.name) {
            case 'openPage':
                throw Error('Not reached');
            case 'closePage':
                return 'close()';
            case 'click': {
                let method = 'click';
                if (action.clickCount === 2)
                    method = 'dblclick';
                const modifiers = utils_1.toModifiers(action.modifiers);
                const options = {};
                if (action.button !== 'left')
                    options.button = action.button;
                if (modifiers.length)
                    options.modifiers = modifiers;
                if (action.clickCount > 2)
                    options.clickCount = action.clickCount;
                const optionsString = formatOptions(options, true);
                return `${method}(${quote(action.selector)}${optionsString})`;
            }
            case 'check':
                return `check(${quote(action.selector)})`;
            case 'uncheck':
                return `uncheck(${quote(action.selector)})`;
            case 'fill':
                return `fill(${quote(action.selector)}, ${quote(action.text)})`;
            case 'setInputFiles':
                return `set_input_files(${quote(action.selector)}, ${formatValue(action.files.length === 1 ? action.files[0] : action.files)})`;
            case 'press': {
                const modifiers = utils_1.toModifiers(action.modifiers);
                const shortcut = [...modifiers, action.key].join('+');
                return `press(${quote(action.selector)}, ${quote(shortcut)})`;
            }
            case 'navigate':
                return `goto(${quote(action.url)})`;
            case 'select':
                return `select_option(${quote(action.selector)}, ${formatValue(action.options.length === 1 ? action.options[0] : action.options)})`;
        }
    }
    generateHeader(options) {
        const formatter = new PythonFormatter();
        if (this._isAsync) {
            formatter.add(`
import asyncio
from playwright.async_api import async_playwright

async def run(playwright) {
    browser = await playwright.${options.browserName}.launch(${formatOptions(options.launchOptions, false)})
    context = await browser.new_context(${formatContextOptions(options.contextOptions, options.deviceName)})`);
        }
        else {
            formatter.add(`
from playwright.sync_api import sync_playwright

def run(playwright) {
    browser = playwright.${options.browserName}.launch(${formatOptions(options.launchOptions, false)})
    context = browser.new_context(${formatContextOptions(options.contextOptions, options.deviceName)})`);
        }
        return formatter.format();
    }
    generateFooter(saveStorage) {
        if (this._isAsync) {
            const storageStateLine = saveStorage ? `\n    await context.storage_state(path="${saveStorage}")` : '';
            return `\n    # ---------------------${storageStateLine}
    await context.close()
    await browser.close()

async def main():
    async with async_playwright() as playwright:
        await run(playwright)
asyncio.run(main())`;
        }
        else {
            const storageStateLine = saveStorage ? `\n    context.storage_state(path="${saveStorage}")` : '';
            return `\n    # ---------------------${storageStateLine}
    context.close()
    browser.close()

with sync_playwright() as playwright:
    run(playwright)`;
        }
    }
}
exports.PythonLanguageGenerator = PythonLanguageGenerator;
function formatValue(value) {
    if (value === false)
        return 'False';
    if (value === true)
        return 'True';
    if (value === undefined)
        return 'None';
    if (Array.isArray(value))
        return `[${value.map(formatValue).join(', ')}]`;
    if (typeof value === 'string')
        return quote(value);
    return String(value);
}
function toSnakeCase(name) {
    const toSnakeCaseRegex = /((?<=[a-z0-9])[A-Z]|(?!^)[A-Z](?=[a-z]))/g;
    return name.replace(toSnakeCaseRegex, `_$1`).toLowerCase();
}
function formatOptions(value, hasArguments) {
    const keys = Object.keys(value);
    if (!keys.length)
        return '';
    return (hasArguments ? ', ' : '') + keys.map(key => `${toSnakeCase(key)}=${formatValue(value[key])}`).join(', ');
}
function formatContextOptions(options, deviceName) {
    const device = deviceName && deviceDescriptors_1.default[deviceName];
    if (!device)
        return formatOptions(options, false);
    return `**playwright.devices["${deviceName}"]` + formatOptions(language_1.sanitizeDeviceOptions(device, options), true);
}
class PythonFormatter {
    constructor(offset = 0) {
        this._lines = [];
        this._baseIndent = ' '.repeat(4);
        this._baseOffset = ' '.repeat(offset);
    }
    prepend(text) {
        this._lines = text.trim().split('\n').map(line => line.trim()).concat(this._lines);
    }
    add(text) {
        this._lines.push(...text.trim().split('\n').map(line => line.trim()));
    }
    newLine() {
        this._lines.push('');
    }
    format() {
        let spaces = '';
        const lines = [];
        this._lines.forEach((line) => {
            if (line === '')
                return lines.push(line);
            if (line === '}') {
                spaces = spaces.substring(this._baseIndent.length);
                return;
            }
            line = spaces + line;
            if (line.endsWith('{')) {
                spaces += this._baseIndent;
                line = line.substring(0, line.length - 1).trimEnd() + ':';
            }
            return lines.push(this._baseOffset + line);
        });
        return lines.join('\n');
    }
}
function quote(text, char = '\"') {
    if (char === '\'')
        return char + text.replace(/[']/g, '\\\'') + char;
    if (char === '"')
        return char + text.replace(/["]/g, '\\"') + char;
    if (char === '`')
        return char + text.replace(/[`]/g, '\\`') + char;
    throw new Error('Invalid escape char');
}
//# sourceMappingURL=python.js.map