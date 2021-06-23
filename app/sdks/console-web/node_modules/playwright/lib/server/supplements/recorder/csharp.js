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
exports.CSharpLanguageGenerator = void 0;
const language_1 = require("./language");
const recorderActions_1 = require("./recorderActions");
const utils_1 = require("./utils");
const deviceDescriptors_1 = __importDefault(require("../../deviceDescriptors"));
class CSharpLanguageGenerator {
    constructor() {
        this.id = 'csharp';
        this.fileName = 'C#';
        this.highlighter = 'csharp';
    }
    generateAction(actionInContext) {
        const { action, pageAlias } = actionInContext;
        const formatter = new CSharpFormatter(8);
        formatter.newLine();
        formatter.add('// ' + recorderActions_1.actionTitle(action));
        if (action.name === 'openPage') {
            formatter.add(`var ${pageAlias} = await context.NewPageAsync();`);
            if (action.url && action.url !== 'about:blank' && action.url !== 'chrome://newtab/')
                formatter.add(`await ${pageAlias}.GotoAsync(${quote(action.url)});`);
            return formatter.format();
        }
        const subject = actionInContext.isMainFrame ? pageAlias :
            (actionInContext.frameName ?
                `${pageAlias}.Frame(${quote(actionInContext.frameName)})` :
                `${pageAlias}.FrameByUrl(${quote(actionInContext.frameUrl)})`);
        const signals = language_1.toSignalMap(action);
        if (signals.dialog) {
            formatter.add(`    void ${pageAlias}_Dialog${signals.dialog.dialogAlias}_EventHandler(object sender, IDialog dialog)
      {
          Console.WriteLine($"Dialog message: {dialog.Message}");
          dialog.DismissAsync();
          ${pageAlias}.Dialog -= ${pageAlias}_Dialog${signals.dialog.dialogAlias}_EventHandler;
      }
      ${pageAlias}.Dialog += ${pageAlias}_Dialog${signals.dialog.dialogAlias}_EventHandler;`);
        }
        const lines = [];
        const actionCall = this._generateActionCall(action, actionInContext.isMainFrame);
        if (signals.waitForNavigation) {
            lines.push(`await ${pageAlias}.RunAndWaitForNavigationAsync(async () =>`);
            lines.push(`{`);
            lines.push(`    await ${subject}.${actionCall};`);
            lines.push(`}/*, new ${actionInContext.isMainFrame ? 'Page' : 'Frame'}WaitForNavigationOptions`);
            lines.push(`{`);
            lines.push(`    UrlString = ${quote(signals.waitForNavigation.url)}`);
            lines.push(`}*/);`);
        }
        else {
            lines.push(`await ${subject}.${actionCall};`);
        }
        if (signals.download) {
            lines.unshift(`var download${signals.download.downloadAlias} = await ${pageAlias}.RunAndWaitForDownloadAsync(async () =>\n{`);
            lines.push(`});`);
        }
        if (signals.popup) {
            lines.unshift(`var ${signals.popup.popupAlias} = await ${pageAlias}.RunAndWaitForPopupAsync(async () =>\n{`);
            lines.push(`});`);
        }
        for (const line of lines)
            formatter.add(line);
        if (signals.assertNavigation)
            formatter.add(`  // Assert.Equal(${quote(signals.assertNavigation.url)}, ${pageAlias}.Url);`);
        return formatter.format();
    }
    _generateActionCall(action, isPage) {
        switch (action.name) {
            case 'openPage':
                throw Error('Not reached');
            case 'closePage':
                return 'CloseAsync()';
            case 'click': {
                let method = 'Click';
                if (action.clickCount === 2)
                    method = 'DblClick';
                const modifiers = utils_1.toModifiers(action.modifiers);
                const options = {};
                if (action.button !== 'left')
                    options.button = action.button;
                if (modifiers.length)
                    options.modifiers = modifiers;
                if (action.clickCount > 2)
                    options.clickCount = action.clickCount;
                if (!Object.entries(options).length)
                    return `${method}Async(${quote(action.selector)})`;
                const optionsString = formatObject(options, '    ', (isPage ? 'Page' : 'Frame') + method + 'Options');
                return `${method}Async(${quote(action.selector)}, ${optionsString})`;
            }
            case 'check':
                return `CheckAsync(${quote(action.selector)})`;
            case 'uncheck':
                return `UncheckAsync(${quote(action.selector)})`;
            case 'fill':
                return `FillAsync(${quote(action.selector)}, ${quote(action.text)})`;
            case 'setInputFiles':
                return `SetInputFilesAsync(${quote(action.selector)}, ${formatObject(action.files)})`;
            case 'press': {
                const modifiers = utils_1.toModifiers(action.modifiers);
                const shortcut = [...modifiers, action.key].join('+');
                return `PressAsync(${quote(action.selector)}, ${quote(shortcut)})`;
            }
            case 'navigate':
                return `GotoAsync(${quote(action.url)})`;
            case 'select':
                return `SelectOptionAsync(${quote(action.selector)}, ${formatObject(action.options)})`;
        }
    }
    generateHeader(options) {
        const formatter = new CSharpFormatter(0);
        formatter.add(`
      using Microsoft.Playwright;
      using System;
      using System.Threading.Tasks;

      class Program
      {
          public static async Task Main()
          {
              using var playwright = await Playwright.CreateAsync();
              await using var browser = await playwright.${toPascal(options.browserName)}.LaunchAsync(${formatObject(options.launchOptions, '    ', 'BrowserTypeLaunchOptions')});
              var context = await browser.NewContextAsync(${formatContextOptions(options.contextOptions, options.deviceName)});`);
        return formatter.format();
    }
    generateFooter(saveStorage) {
        const storageStateLine = saveStorage ? `\n        await context.StorageStateAsync(new BrowserContextStorageStateOptions\n        {\n            Path = ${quote(saveStorage)}\n        });\n` : '';
        return `${storageStateLine}    }
}\n`;
    }
}
exports.CSharpLanguageGenerator = CSharpLanguageGenerator;
function formatObject(value, indent = '    ', name = '') {
    if (typeof value === 'string') {
        if (['permissions', 'colorScheme', 'modifiers', 'button'].includes(name))
            return `${getClassName(name)}.${toPascal(value)}`;
        return quote(value);
    }
    if (Array.isArray(value))
        return `new[] { ${value.map(o => formatObject(o, indent, name)).join(', ')} }`;
    if (typeof value === 'object') {
        const keys = Object.keys(value);
        if (!keys.length)
            return name ? `new ${getClassName(name)}` : '';
        const tokens = [];
        for (const key of keys) {
            const property = getPropertyName(key);
            tokens.push(`${property} = ${formatObject(value[key], indent, key)},`);
        }
        if (name)
            return `new ${getClassName(name)}\n{\n${indent}${tokens.join(`\n${indent}`)}\n${indent}}`;
        return `{\n${indent}${tokens.join(`\n${indent}`)}\n${indent}}`;
    }
    if (name === 'latitude' || name === 'longitude')
        return String(value) + 'm';
    return String(value);
}
function getClassName(value) {
    switch (value) {
        case 'viewport': return 'ViewportSize';
        case 'proxy': return 'ProxySettings';
        case 'permissions': return 'ContextPermission';
        case 'modifiers': return 'KeyboardModifier';
        case 'button': return 'MouseButton';
        default: return toPascal(value);
    }
}
function getPropertyName(key) {
    switch (key) {
        case 'storageState': return 'StorageStatePath';
        case 'viewport': return 'ViewportSize';
        default: return toPascal(key);
    }
}
function toPascal(value) {
    return value[0].toUpperCase() + value.slice(1);
}
function formatContextOptions(options, deviceName) {
    const device = deviceName && deviceDescriptors_1.default[deviceName];
    if (!device) {
        if (!Object.entries(options).length)
            return '';
        return formatObject(options, '    ', 'BrowserNewContextOptions');
    }
    options = language_1.sanitizeDeviceOptions(device, options);
    if (!Object.entries(options).length)
        return `playwright.Devices[${quote(deviceName)}]`;
    return formatObject(options, '    ', `BrowserNewContextOptions(playwright.Devices[${quote(deviceName)}])`);
}
class CSharpFormatter {
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
        let previousLine = '';
        return this._lines.map((line) => {
            if (line === '')
                return line;
            if (line.startsWith('}') || line.startsWith(']') || line.includes('});') || line === ');')
                spaces = spaces.substring(this._baseIndent.length);
            const extraSpaces = /^(for|while|if).*\(.*\)$/.test(previousLine) ? this._baseIndent : '';
            previousLine = line;
            line = spaces + extraSpaces + line;
            if (line.endsWith('{') || line.endsWith('[') || line.endsWith('('))
                spaces += this._baseIndent;
            if (line.endsWith('));'))
                spaces = spaces.substring(this._baseIndent.length);
            return this._baseOffset + line;
        }).join('\n');
    }
}
function quote(text) {
    return `"${text.replace(/["]/g, '\\"')}"`;
}
//# sourceMappingURL=csharp.js.map