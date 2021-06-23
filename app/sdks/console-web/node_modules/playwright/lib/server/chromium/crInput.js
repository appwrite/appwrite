"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an 'AS IS' BASIS,
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
exports.RawTouchscreenImpl = exports.RawMouseImpl = exports.RawKeyboardImpl = void 0;
const input = __importStar(require("../input"));
const macEditingCommands_1 = require("../macEditingCommands");
const utils_1 = require("../../utils/utils");
const crProtocolHelper_1 = require("./crProtocolHelper");
class RawKeyboardImpl {
    constructor(_client, _isMac, _dragManger) {
        this._client = _client;
        this._isMac = _isMac;
        this._dragManger = _dragManger;
    }
    _commandsForCode(code, modifiers) {
        if (!this._isMac)
            return [];
        const parts = [];
        for (const modifier of (['Shift', 'Control', 'Alt', 'Meta'])) {
            if (modifiers.has(modifier))
                parts.push(modifier);
        }
        parts.push(code);
        const shortcut = parts.join('+');
        let commands = macEditingCommands_1.macEditingCommands[shortcut] || [];
        if (utils_1.isString(commands))
            commands = [commands];
        // Commands that insert text are not supported
        commands = commands.filter(x => !x.startsWith('insert'));
        // remove the trailing : to match the Chromium command names.
        return commands.map(c => c.substring(0, c.length - 1));
    }
    async keydown(modifiers, code, keyCode, keyCodeWithoutLocation, key, location, autoRepeat, text) {
        if (code === 'Escape' && await this._dragManger.cancelDrag())
            return;
        const commands = this._commandsForCode(code, modifiers);
        await this._client.send('Input.dispatchKeyEvent', {
            type: text ? 'keyDown' : 'rawKeyDown',
            modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            windowsVirtualKeyCode: keyCodeWithoutLocation,
            code,
            commands,
            key,
            text,
            unmodifiedText: text,
            autoRepeat,
            location,
            isKeypad: location === input.keypadLocation
        });
    }
    async keyup(modifiers, code, keyCode, keyCodeWithoutLocation, key, location) {
        await this._client.send('Input.dispatchKeyEvent', {
            type: 'keyUp',
            modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            key,
            windowsVirtualKeyCode: keyCodeWithoutLocation,
            code,
            location
        });
    }
    async sendText(text) {
        await this._client.send('Input.insertText', { text });
    }
}
exports.RawKeyboardImpl = RawKeyboardImpl;
class RawMouseImpl {
    constructor(page, client, dragManager) {
        this._page = page;
        this._client = client;
        this._dragManager = dragManager;
    }
    async move(x, y, button, buttons, modifiers) {
        await this._dragManager.interceptDragCausedByMove(x, y, button, buttons, modifiers, async () => {
            await this._client.send('Input.dispatchMouseEvent', {
                type: 'mouseMoved',
                button,
                x,
                y,
                modifiers: crProtocolHelper_1.toModifiersMask(modifiers)
            });
        });
    }
    async down(x, y, button, buttons, modifiers, clickCount) {
        if (this._dragManager.isDragging())
            return;
        await this._client.send('Input.dispatchMouseEvent', {
            type: 'mousePressed',
            button,
            x,
            y,
            modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            clickCount
        });
    }
    async up(x, y, button, buttons, modifiers, clickCount) {
        if (this._dragManager.isDragging()) {
            await this._dragManager.drop(x, y, modifiers);
            return;
        }
        await this._client.send('Input.dispatchMouseEvent', {
            type: 'mouseReleased',
            button,
            x,
            y,
            modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
            clickCount
        });
    }
}
exports.RawMouseImpl = RawMouseImpl;
class RawTouchscreenImpl {
    constructor(client) {
        this._client = client;
    }
    async tap(x, y, modifiers) {
        await Promise.all([
            this._client.send('Input.dispatchTouchEvent', {
                type: 'touchStart',
                modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
                touchPoints: [{
                        x, y
                    }]
            }),
            this._client.send('Input.dispatchTouchEvent', {
                type: 'touchEnd',
                modifiers: crProtocolHelper_1.toModifiersMask(modifiers),
                touchPoints: []
            }),
        ]);
    }
}
exports.RawTouchscreenImpl = RawTouchscreenImpl;
//# sourceMappingURL=crInput.js.map