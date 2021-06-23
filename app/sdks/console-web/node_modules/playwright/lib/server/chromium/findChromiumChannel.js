"use strict";
/**
 * Copyright (c) Microsoft Corporation. All rights reserved.
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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.findChromiumChannel = void 0;
const path_1 = __importDefault(require("path"));
const utils_1 = require("../../utils/utils");
function darwin(channel) {
    switch (channel) {
        case 'chrome': return ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'];
        case 'chrome-beta': return ['/Applications/Google Chrome Beta.app/Contents/MacOS/Google Chrome Beta'];
        case 'chrome-dev': return ['/Applications/Google Chrome Dev.app/Contents/MacOS/Google Chrome Dev'];
        case 'chrome-canary': return ['/Applications/Google Chrome Canary.app/Contents/MacOS/Google Chrome Canary'];
        case 'msedge': return ['/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'];
        case 'msedge-beta': return ['/Applications/Microsoft Edge Beta.app/Contents/MacOS/Microsoft Edge Beta'];
        case 'msedge-dev': return ['/Applications/Microsoft Edge Dev.app/Contents/MacOS/Microsoft Edge Dev'];
        case 'msedge-canary': return ['/Applications/Microsoft Edge Canary.app/Contents/MacOS/Microsoft Edge Canary'];
    }
}
function linux(channel) {
    switch (channel) {
        case 'chrome': return ['/opt/google/chrome/chrome'];
        case 'chrome-beta': return ['/opt/google/chrome-beta/chrome'];
        case 'chrome-dev': return ['/opt/google/chrome-unstable/chrome'];
        case 'msedge-dev': return ['/opt/microsoft/msedge-dev/msedge'];
    }
}
function win32(channel) {
    let suffix;
    switch (channel) {
        case 'chrome':
            suffix = `\\Google\\Chrome\\Application\\chrome.exe`;
            break;
        case 'chrome-beta':
            suffix = `\\Google\\Chrome Beta\\Application\\chrome.exe`;
            break;
        case 'chrome-dev':
            suffix = `\\Google\\Chrome Dev\\Application\\chrome.exe`;
            break;
        case 'chrome-canary':
            suffix = `\\Google\\Chrome SxS\\Application\\chrome.exe`;
            break;
        case 'msedge':
            suffix = `\\Microsoft\\Edge\\Application\\msedge.exe`;
            break;
        case 'msedge-beta':
            suffix = `\\Microsoft\\Edge Beta\\Application\\msedge.exe`;
            break;
        case 'msedge-dev':
            suffix = `\\Microsoft\\Edge Dev\\Application\\msedge.exe`;
            break;
        case 'msedge-canary':
            suffix = `\\Microsoft\\Edge SxS\\Application\\msedge.exe`;
            break;
    }
    if (!suffix)
        return;
    const prefixes = [
        process.env.LOCALAPPDATA, process.env.PROGRAMFILES, process.env['PROGRAMFILES(X86)']
    ].filter(Boolean);
    return prefixes.map(prefix => path_1.default.join(prefix, suffix));
}
function findChromiumChannel(channel) {
    let installationPaths;
    if (process.platform === 'linux')
        installationPaths = linux(channel);
    else if (process.platform === 'win32')
        installationPaths = win32(channel);
    else if (process.platform === 'darwin')
        installationPaths = darwin(channel);
    if (!installationPaths)
        throw new Error(`Chromium distribution '${channel}' is not supported on ${process.platform}`);
    let result;
    installationPaths.forEach(chromePath => {
        if (utils_1.canAccessFile(chromePath))
            result = chromePath;
    });
    if (result)
        return result;
    throw new Error(`Chromium distribution is not installed on the system: ${channel}`);
}
exports.findChromiumChannel = findChromiumChannel;
//# sourceMappingURL=findChromiumChannel.js.map