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
exports.getUbuntuVersionSync = exports.getUbuntuVersion = void 0;
const fs_1 = __importDefault(require("fs"));
const os = __importStar(require("os"));
async function getUbuntuVersion() {
    if (os.platform() !== 'linux')
        return '';
    let osReleaseText = await fs_1.default.promises.readFile('/etc/upstream-release/lsb-release', 'utf8').catch(e => '');
    if (!osReleaseText)
        osReleaseText = await fs_1.default.promises.readFile('/etc/os-release', 'utf8').catch(e => '');
    if (!osReleaseText)
        return '';
    return getUbuntuVersionInternal(osReleaseText);
}
exports.getUbuntuVersion = getUbuntuVersion;
function getUbuntuVersionSync() {
    if (os.platform() !== 'linux')
        return '';
    try {
        let osReleaseText;
        if (fs_1.default.existsSync('/etc/upstream-release/lsb-release'))
            osReleaseText = fs_1.default.readFileSync('/etc/upstream-release/lsb-release', 'utf8');
        else
            osReleaseText = fs_1.default.readFileSync('/etc/os-release', 'utf8');
        if (!osReleaseText)
            return '';
        return getUbuntuVersionInternal(osReleaseText);
    }
    catch (e) {
        return '';
    }
}
exports.getUbuntuVersionSync = getUbuntuVersionSync;
function getUbuntuVersionInternal(osReleaseText) {
    const fields = new Map();
    for (const line of osReleaseText.split('\n')) {
        const tokens = line.split('=');
        const name = tokens.shift();
        let value = tokens.join('=').trim();
        if (value.startsWith('"') && value.endsWith('"'))
            value = value.substring(1, value.length - 1);
        if (!name)
            continue;
        fields.set(name.toLowerCase(), value);
    }
    // For Linux mint
    if (fields.get('distrib_id') && fields.get('distrib_id').toLowerCase() === 'ubuntu')
        return fields.get('distrib_release') || '';
    if (!fields.get('name') || fields.get('name').toLowerCase() !== 'ubuntu')
        return '';
    return fields.get('version_id') || '';
}
//# sourceMappingURL=ubuntuVersion.js.map