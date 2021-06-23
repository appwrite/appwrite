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
exports.Firefox = void 0;
const os = __importStar(require("os"));
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const utils_1 = require("../../utils/utils");
const ffBrowser_1 = require("./ffBrowser");
const ffConnection_1 = require("./ffConnection");
const browserType_1 = require("../browserType");
class Firefox extends browserType_1.BrowserType {
    constructor(playwrightOptions) {
        super('firefox', playwrightOptions);
    }
    executablePath(channel) {
        if (channel) {
            let executablePath = undefined;
            if (channel === 'firefox-beta')
                executablePath = this._registry.executablePath('firefox-beta');
            utils_1.assert(executablePath, `unsupported firefox channel "${channel}"`);
            utils_1.assert(fs_1.default.existsSync(executablePath), `"${channel}" channel is not installed. Try running 'npx playwright install ${channel}'`);
            return executablePath;
        }
        return super.executablePath(channel);
    }
    _connectToTransport(transport, options) {
        return ffBrowser_1.FFBrowser.connect(transport, options);
    }
    _rewriteStartupError(error) {
        return error;
    }
    _amendEnvironment(env, userDataDir, executable, browserArguments) {
        if (!path_1.default.isAbsolute(os.homedir()))
            throw new Error(`Cannot launch Firefox with relative home directory. Did you set ${os.platform() === 'win32' ? 'USERPROFILE' : 'HOME'} to a relative path?`);
        if (os.platform() === 'linux') {
            return {
                ...env,
                // On linux Juggler ships the libstdc++ it was linked against.
                LD_LIBRARY_PATH: `${path_1.default.dirname(executable)}:${process.env.LD_LIBRARY_PATH}`,
            };
        }
        if (os.platform() === 'darwin') {
            return {
                ...env,
                // @see https://github.com/microsoft/playwright/issues/5721
                MOZ_WEBRENDER: 0,
            };
        }
        return env;
    }
    _attemptToGracefullyCloseBrowser(transport) {
        const message = { method: 'Browser.close', params: {}, id: ffConnection_1.kBrowserCloseMessageId };
        transport.send(message);
    }
    _defaultArgs(options, isPersistent, userDataDir) {
        const { args = [], devtools, headless } = options;
        if (devtools)
            console.warn('devtools parameter is not supported as a launch argument in Firefox. You can launch the devtools window manually.');
        const userDataDirArg = args.find(arg => arg.startsWith('-profile') || arg.startsWith('--profile'));
        if (userDataDirArg)
            throw new Error('Pass userDataDir parameter to `browserType.launchPersistentContext(userDataDir, ...)` instead of specifying --profile argument');
        if (args.find(arg => arg.startsWith('-juggler')))
            throw new Error('Use the port parameter instead of -juggler argument');
        const firefoxUserPrefs = isPersistent ? undefined : options.firefoxUserPrefs;
        if (firefoxUserPrefs) {
            const lines = [];
            for (const [name, value] of Object.entries(firefoxUserPrefs))
                lines.push(`user_pref(${JSON.stringify(name)}, ${JSON.stringify(value)});`);
            fs_1.default.writeFileSync(path_1.default.join(userDataDir, 'user.js'), lines.join('\n'));
        }
        const firefoxArguments = ['-no-remote'];
        if (headless) {
            firefoxArguments.push('-headless');
        }
        else {
            firefoxArguments.push('-wait-for-browser');
            firefoxArguments.push('-foreground');
        }
        firefoxArguments.push(`-profile`, userDataDir);
        firefoxArguments.push('-juggler-pipe');
        firefoxArguments.push(...args);
        if (isPersistent)
            firefoxArguments.push('about:blank');
        else
            firefoxArguments.push('-silent');
        return firefoxArguments;
    }
}
exports.Firefox = Firefox;
//# sourceMappingURL=firefox.js.map