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
Object.defineProperty(exports, "__esModule", { value: true });
exports.Touchscreen = exports.Mouse = exports.Keyboard = void 0;
class Keyboard {
    constructor(channel) {
        this._channel = channel;
    }
    async down(key) {
        await this._channel.keyboardDown({ key });
    }
    async up(key) {
        await this._channel.keyboardUp({ key });
    }
    async insertText(text) {
        await this._channel.keyboardInsertText({ text });
    }
    async type(text, options = {}) {
        await this._channel.keyboardType({ text, ...options });
    }
    async press(key, options = {}) {
        await this._channel.keyboardPress({ key, ...options });
    }
}
exports.Keyboard = Keyboard;
class Mouse {
    constructor(channel) {
        this._channel = channel;
    }
    async move(x, y, options = {}) {
        await this._channel.mouseMove({ x, y, ...options });
    }
    async down(options = {}) {
        await this._channel.mouseDown({ ...options });
    }
    async up(options = {}) {
        await this._channel.mouseUp(options);
    }
    async click(x, y, options = {}) {
        await this._channel.mouseClick({ x, y, ...options });
    }
    async dblclick(x, y, options = {}) {
        await this.click(x, y, { ...options, clickCount: 2 });
    }
}
exports.Mouse = Mouse;
class Touchscreen {
    constructor(channel) {
        this._channel = channel;
    }
    async tap(x, y) {
        await this._channel.touchscreenTap({ x, y });
    }
}
exports.Touchscreen = Touchscreen;
//# sourceMappingURL=input.js.map