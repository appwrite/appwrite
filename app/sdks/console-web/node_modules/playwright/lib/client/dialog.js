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
exports.Dialog = void 0;
const channelOwner_1 = require("./channelOwner");
class Dialog extends channelOwner_1.ChannelOwner {
    static from(dialog) {
        return dialog._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
    }
    type() {
        return this._initializer.type;
    }
    message() {
        return this._initializer.message;
    }
    defaultValue() {
        return this._initializer.defaultValue;
    }
    async accept(promptText) {
        return this._wrapApiCall('dialog.accept', async (channel) => {
            await channel.accept({ promptText });
        });
    }
    async dismiss() {
        return this._wrapApiCall('dialog.dismiss', async (channel) => {
            await channel.dismiss();
        });
    }
}
exports.Dialog = Dialog;
//# sourceMappingURL=dialog.js.map