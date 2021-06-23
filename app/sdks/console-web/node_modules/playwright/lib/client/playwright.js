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
exports.Playwright = void 0;
const browserType_1 = require("./browserType");
const channelOwner_1 = require("./channelOwner");
const selectors_1 = require("./selectors");
const electron_1 = require("./electron");
const errors_1 = require("../utils/errors");
const android_1 = require("./android");
const socksSocket_1 = require("./socksSocket");
class Playwright extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._forwardPorts = [];
        this.chromium = browserType_1.BrowserType.from(initializer.chromium);
        this.firefox = browserType_1.BrowserType.from(initializer.firefox);
        this.webkit = browserType_1.BrowserType.from(initializer.webkit);
        this._android = android_1.Android.from(initializer.android);
        this._electron = electron_1.Electron.from(initializer.electron);
        this.devices = {};
        for (const { name, descriptor } of initializer.deviceDescriptors)
            this.devices[name] = descriptor;
        this.selectors = selectors_1.sharedSelectors;
        this.errors = { TimeoutError: errors_1.TimeoutError };
        this._selectorsOwner = selectors_1.SelectorsOwner.from(initializer.selectors);
        this.selectors._addChannel(this._selectorsOwner);
        this._channel.on('incomingSocksSocket', ({ socket }) => socksSocket_1.SocksSocket.from(socket));
    }
    async _enablePortForwarding(ports) {
        this._forwardPorts = ports;
        await this._channel.setForwardedPorts({ ports });
    }
    _cleanup() {
        this.selectors._removeChannel(this._selectorsOwner);
    }
}
exports.Playwright = Playwright;
//# sourceMappingURL=playwright.js.map