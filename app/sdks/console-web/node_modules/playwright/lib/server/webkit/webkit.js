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
exports.WebKit = void 0;
const wkBrowser_1 = require("../webkit/wkBrowser");
const path_1 = __importDefault(require("path"));
const wkConnection_1 = require("./wkConnection");
const browserType_1 = require("../browserType");
const fs = __importStar(require("fs"));
const utils_1 = require("../../utils/utils");
class WebKit extends browserType_1.BrowserType {
    constructor(playwrightOptions) {
        super('webkit', playwrightOptions);
    }
    executablePath(channel) {
        if (channel) {
            let executablePath = undefined;
            if (channel === 'technology-preview')
                executablePath = this._registry.executablePath('webkit-technology-preview');
            utils_1.assert(executablePath, `unsupported webkit channel "${channel}"`);
            utils_1.assert(fs.existsSync(executablePath), `webkit channel "${channel}" is not installed. Try running 'npx playwright install webkit-technology-preview'`);
            return executablePath;
        }
        return super.executablePath(channel);
    }
    _connectToTransport(transport, options) {
        return wkBrowser_1.WKBrowser.connect(transport, options);
    }
    _amendEnvironment(env, userDataDir, executable, browserArguments) {
        return { ...env, CURL_COOKIE_JAR_PATH: path_1.default.join(userDataDir, 'cookiejar.db') };
    }
    _rewriteStartupError(error) {
        return error;
    }
    _attemptToGracefullyCloseBrowser(transport) {
        transport.send({ method: 'Playwright.close', params: {}, id: wkConnection_1.kBrowserCloseMessageId });
    }
    _defaultArgs(options, isPersistent, userDataDir) {
        const { args = [], proxy, devtools, headless } = options;
        if (devtools)
            console.warn('devtools parameter as a launch argument in WebKit is not supported. Also starting Web Inspector manually will terminate the execution in WebKit.');
        const userDataDirArg = args.find(arg => arg.startsWith('--user-data-dir'));
        if (userDataDirArg)
            throw new Error('Pass userDataDir parameter to `browserType.launchPersistentContext(userDataDir, ...)` instead of specifying --user-data-dir argument');
        if (args.find(arg => !arg.startsWith('-')))
            throw new Error('Arguments can not specify page to be opened');
        const webkitArguments = ['--inspector-pipe'];
        if (headless)
            webkitArguments.push('--headless');
        if (isPersistent)
            webkitArguments.push(`--user-data-dir=${userDataDir}`);
        else
            webkitArguments.push(`--no-startup-window`);
        if (proxy) {
            if (process.platform === 'darwin') {
                webkitArguments.push(`--proxy=${proxy.server}`);
                if (proxy.bypass)
                    webkitArguments.push(`--proxy-bypass-list=${proxy.bypass}`);
            }
            else if (process.platform === 'linux') {
                webkitArguments.push(`--proxy=${proxy.server}`);
                if (proxy.bypass)
                    webkitArguments.push(...proxy.bypass.split(',').map(t => `--ignore-host=${t}`));
            }
            else if (process.platform === 'win32') {
                webkitArguments.push(`--curl-proxy=${proxy.server}`);
                if (proxy.bypass)
                    webkitArguments.push(`--curl-noproxy=${proxy.bypass}`);
            }
        }
        webkitArguments.push(...args);
        if (isPersistent)
            webkitArguments.push('about:blank');
        return webkitArguments;
    }
}
exports.WebKit = WebKit;
//# sourceMappingURL=webkit.js.map