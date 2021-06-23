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
exports.createPlaywright = exports.Playwright = void 0;
const path_1 = __importDefault(require("path"));
const android_1 = require("./android/android");
const backendAdb_1 = require("./android/backendAdb");
const chromium_1 = require("./chromium/chromium");
const electron_1 = require("./electron/electron");
const firefox_1 = require("./firefox/firefox");
const selectors_1 = require("./selectors");
const webkit_1 = require("./webkit/webkit");
const registry_1 = require("../utils/registry");
const instrumentation_1 = require("./instrumentation");
const debugLogger_1 = require("../utils/debugLogger");
const socksSocket_1 = require("./socksSocket");
const utils_1 = require("../utils/utils");
class Playwright extends instrumentation_1.SdkObject {
    constructor(isInternal) {
        super({ attribution: { isInternal }, instrumentation: instrumentation_1.createInstrumentation() }, undefined, 'Playwright');
        this.instrumentation.addListener({
            onCallLog: (logName, message, sdkObject, metadata) => {
                debugLogger_1.debugLogger.log(logName, message);
            }
        });
        this.options = {
            registry: new registry_1.Registry(path_1.default.join(__dirname, '..', '..')),
            rootSdkObject: this,
        };
        this.chromium = new chromium_1.Chromium(this.options);
        this.firefox = new firefox_1.Firefox(this.options);
        this.webkit = new webkit_1.WebKit(this.options);
        this.electron = new electron_1.Electron(this.options);
        this.android = new android_1.Android(new backendAdb_1.AdbBackend(), this.options);
        this.selectors = selectors_1.serverSelectors;
    }
    async _enablePortForwarding() {
        utils_1.assert(!this._portForwardingServer);
        this._portForwardingServer = await socksSocket_1.PortForwardingServer.create(this);
        this.options.loopbackProxyOverride = () => this._portForwardingServer.proxyServer();
        this._portForwardingServer.on('incomingSocksSocket', (socket) => {
            this.emit('incomingSocksSocket', socket);
        });
    }
    _disablePortForwarding() {
        if (!this._portForwardingServer)
            return;
        this._portForwardingServer.stop();
    }
    _setForwardedPorts(ports) {
        if (!this._portForwardingServer)
            throw new Error(`Port forwarding needs to be enabled when launching the server via BrowserType.launchServer.`);
        this._portForwardingServer.setForwardedPorts(ports);
    }
}
exports.Playwright = Playwright;
function createPlaywright(isInternal = false) {
    return new Playwright(isInternal);
}
exports.createPlaywright = createPlaywright;
//# sourceMappingURL=playwright.js.map