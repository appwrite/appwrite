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
Object.defineProperty(exports, "__esModule", { value: true });
exports.Touchscreen = exports.Mouse = exports.Keyboard = exports.keypadLocation = void 0;
const utils_1 = require("../utils/utils");
const keyboardLayout = __importStar(require("./usKeyboardLayout"));
exports.keypadLocation = keyboardLayout.keypadLocation;
const kModifiers = ['Alt', 'Control', 'Meta', 'Shift'];
class Keyboard {
    constructor(raw, page) {
        this._pressedModifiers = new Set();
        this._pressedKeys = new Set();
        this._raw = raw;
        this._page = page;
    }
    async down(key) {
        const description = this._keyDescriptionForString(key);
        const autoRepeat = this._pressedKeys.has(description.code);
        this._pressedKeys.add(description.code);
        if (kModifiers.includes(description.key))
            this._pressedModifiers.add(description.key);
        const text = description.text;
        await this._raw.keydown(this._pressedModifiers, description.code, description.keyCode, description.keyCodeWithoutLocation, description.key, description.location, autoRepeat, text);
        await this._page._doSlowMo();
    }
    _keyDescriptionForString(keyString) {
        let description = usKeyboardLayout.get(keyString);
        utils_1.assert(description, `Unknown key: "${keyString}"`);
        const shift = this._pressedModifiers.has('Shift');
        description = shift && description.shifted ? description.shifted : description;
        // if any modifiers besides shift are pressed, no text should be sent
        if (this._pressedModifiers.size > 1 || (!this._pressedModifiers.has('Shift') && this._pressedModifiers.size === 1))
            return { ...description, text: '' };
        return description;
    }
    async up(key) {
        const description = this._keyDescriptionForString(key);
        if (kModifiers.includes(description.key))
            this._pressedModifiers.delete(description.key);
        this._pressedKeys.delete(description.code);
        await this._raw.keyup(this._pressedModifiers, description.code, description.keyCode, description.keyCodeWithoutLocation, description.key, description.location);
        await this._page._doSlowMo();
    }
    async insertText(text) {
        await this._raw.sendText(text);
        await this._page._doSlowMo();
    }
    async type(text, options) {
        const delay = (options && options.delay) || undefined;
        for (const char of text) {
            if (usKeyboardLayout.has(char)) {
                await this.press(char, { delay });
            }
            else {
                if (delay)
                    await new Promise(f => setTimeout(f, delay));
                await this.insertText(char);
            }
        }
    }
    async press(key, options = {}) {
        function split(keyString) {
            const keys = [];
            let building = '';
            for (const char of keyString) {
                if (char === '+' && building) {
                    keys.push(building);
                    building = '';
                }
                else {
                    building += char;
                }
            }
            keys.push(building);
            return keys;
        }
        const tokens = split(key);
        const promises = [];
        key = tokens[tokens.length - 1];
        for (let i = 0; i < tokens.length - 1; ++i)
            promises.push(this.down(tokens[i]));
        promises.push(this.down(key));
        if (options.delay) {
            await Promise.all(promises);
            await new Promise(f => setTimeout(f, options.delay));
        }
        promises.push(this.up(key));
        for (let i = tokens.length - 2; i >= 0; --i)
            promises.push(this.up(tokens[i]));
        await Promise.all(promises);
    }
    async _ensureModifiers(modifiers) {
        for (const modifier of modifiers) {
            if (!kModifiers.includes(modifier))
                throw new Error('Unknown modifier ' + modifier);
        }
        const restore = Array.from(this._pressedModifiers);
        const promises = [];
        for (const key of kModifiers) {
            const needDown = modifiers.includes(key);
            const isDown = this._pressedModifiers.has(key);
            if (needDown && !isDown)
                promises.push(this.down(key));
            else if (!needDown && isDown)
                promises.push(this.up(key));
        }
        await Promise.all(promises);
        return restore;
    }
    _modifiers() {
        return this._pressedModifiers;
    }
}
exports.Keyboard = Keyboard;
class Mouse {
    constructor(raw, page) {
        this._x = 0;
        this._y = 0;
        this._lastButton = 'none';
        this._buttons = new Set();
        this._raw = raw;
        this._page = page;
        this._keyboard = this._page.keyboard;
    }
    async move(x, y, options = {}) {
        const { steps = 1 } = options;
        const fromX = this._x;
        const fromY = this._y;
        this._x = x;
        this._y = y;
        for (let i = 1; i <= steps; i++) {
            const middleX = fromX + (x - fromX) * (i / steps);
            const middleY = fromY + (y - fromY) * (i / steps);
            await this._raw.move(middleX, middleY, this._lastButton, this._buttons, this._keyboard._modifiers());
            await this._page._doSlowMo();
        }
    }
    async down(options = {}) {
        const { button = 'left', clickCount = 1 } = options;
        this._lastButton = button;
        this._buttons.add(button);
        await this._raw.down(this._x, this._y, this._lastButton, this._buttons, this._keyboard._modifiers(), clickCount);
        await this._page._doSlowMo();
    }
    async up(options = {}) {
        const { button = 'left', clickCount = 1 } = options;
        this._lastButton = 'none';
        this._buttons.delete(button);
        await this._raw.up(this._x, this._y, button, this._buttons, this._keyboard._modifiers(), clickCount);
        await this._page._doSlowMo();
    }
    async click(x, y, options = {}) {
        const { delay = null, clickCount = 1 } = options;
        if (delay) {
            this.move(x, y);
            for (let cc = 1; cc <= clickCount; ++cc) {
                await this.down({ ...options, clickCount: cc });
                await new Promise(f => setTimeout(f, delay));
                await this.up({ ...options, clickCount: cc });
                if (cc < clickCount)
                    await new Promise(f => setTimeout(f, delay));
            }
        }
        else {
            const promises = [];
            promises.push(this.move(x, y));
            for (let cc = 1; cc <= clickCount; ++cc) {
                promises.push(this.down({ ...options, clickCount: cc }));
                promises.push(this.up({ ...options, clickCount: cc }));
            }
            await Promise.all(promises);
        }
    }
    async dblclick(x, y, options = {}) {
        await this.click(x, y, { ...options, clickCount: 2 });
    }
}
exports.Mouse = Mouse;
const aliases = new Map([
    ['ShiftLeft', ['Shift']],
    ['ControlLeft', ['Control']],
    ['AltLeft', ['Alt']],
    ['MetaLeft', ['Meta']],
    ['Enter', ['\n', '\r']],
]);
const usKeyboardLayout = buildLayoutClosure(keyboardLayout.USKeyboardLayout);
function buildLayoutClosure(layout) {
    const result = new Map();
    for (const code in layout) {
        const definition = layout[code];
        const description = {
            key: definition.key || '',
            keyCode: definition.keyCode || 0,
            keyCodeWithoutLocation: definition.keyCodeWithoutLocation || definition.keyCode || 0,
            code,
            text: definition.text || '',
            location: definition.location || 0,
        };
        if (definition.key.length === 1)
            description.text = description.key;
        // Generate shifted definition.
        let shiftedDescription;
        if (definition.shiftKey) {
            utils_1.assert(definition.shiftKey.length === 1);
            shiftedDescription = { ...description };
            shiftedDescription.key = definition.shiftKey;
            shiftedDescription.text = definition.shiftKey;
            if (definition.shiftKeyCode)
                shiftedDescription.keyCode = definition.shiftKeyCode;
        }
        // Map from code: Digit3 -> { ... descrption, shifted }
        result.set(code, { ...description, shifted: shiftedDescription });
        // Map from aliases: Shift -> non-shiftable definition
        if (aliases.has(code)) {
            for (const alias of aliases.get(code))
                result.set(alias, description);
        }
        // Do not use numpad when converting keys to codes.
        if (definition.location)
            continue;
        // Map from key, no shifted
        if (description.key.length === 1)
            result.set(description.key, description);
        // Map from shiftKey, no shifted
        if (shiftedDescription)
            result.set(shiftedDescription.key, { ...shiftedDescription, shifted: undefined });
    }
    return result;
}
class Touchscreen {
    constructor(raw, page) {
        this._raw = raw;
        this._page = page;
    }
    async tap(x, y) {
        if (!this._page._browserContext._options.hasTouch)
            throw new Error('hasTouch must be enabled on the browser context before using the touchscreen.');
        await this._raw.tap(x, y, this._page.keyboard._modifiers());
        await this._page._doSlowMo();
    }
}
exports.Touchscreen = Touchscreen;
//# sourceMappingURL=input.js.map