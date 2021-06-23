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
exports.Dialog = void 0;
const utils_1 = require("../utils/utils");
const instrumentation_1 = require("./instrumentation");
class Dialog extends instrumentation_1.SdkObject {
    constructor(page, type, message, onHandle, defaultValue) {
        super(page, 'dialog');
        this._handled = false;
        this._page = page;
        this._type = type;
        this._message = message;
        this._onHandle = onHandle;
        this._defaultValue = defaultValue || '';
    }
    type() {
        return this._type;
    }
    message() {
        return this._message;
    }
    defaultValue() {
        return this._defaultValue;
    }
    async accept(promptText) {
        utils_1.assert(!this._handled, 'Cannot accept dialog which is already handled!');
        this._handled = true;
        await this._onHandle(true, promptText);
    }
    async dismiss() {
        utils_1.assert(!this._handled, 'Cannot dismiss dialog which is already handled!');
        this._handled = true;
        await this._onHandle(false);
    }
}
exports.Dialog = Dialog;
//# sourceMappingURL=dialog.js.map