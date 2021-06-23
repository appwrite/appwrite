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
exports.Registry = exports.isBrowserDirectory = exports.registryDirectory = exports.hostPlatform = exports.allBrowserNames = void 0;
const child_process_1 = require("child_process");
const os = __importStar(require("os"));
const path_1 = __importDefault(require("path"));
const util = __importStar(require("util"));
const ubuntuVersion_1 = require("./ubuntuVersion");
const utils_1 = require("./utils");
exports.allBrowserNames = new Set(['chromium', 'webkit', 'firefox', 'ffmpeg', 'webkit-technology-preview', 'firefox-beta']);
const PACKAGE_PATH = path_1.default.join(__dirname, '..', '..');
const EXECUTABLE_PATHS = {
    'chromium': {
        'ubuntu18.04': ['chrome-linux', 'chrome'],
        'ubuntu20.04': ['chrome-linux', 'chrome'],
        'mac10.13': ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
        'mac10.14': ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
        'mac10.15': ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
        'mac11': ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
        'mac11-arm64': ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
        'win32': ['chrome-win', 'chrome.exe'],
        'win64': ['chrome-win', 'chrome.exe'],
    },
    'firefox': {
        'ubuntu18.04': ['firefox', 'firefox'],
        'ubuntu20.04': ['firefox', 'firefox'],
        'mac10.13': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac10.14': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac10.15': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac11': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac11-arm64': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'win32': ['firefox', 'firefox.exe'],
        'win64': ['firefox', 'firefox.exe'],
    },
    'firefox-beta': {
        'ubuntu18.04': ['firefox', 'firefox'],
        'ubuntu20.04': ['firefox', 'firefox'],
        'mac10.13': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac10.14': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac10.15': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac11': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'mac11-arm64': ['firefox', 'Nightly.app', 'Contents', 'MacOS', 'firefox'],
        'win32': ['firefox', 'firefox.exe'],
        'win64': ['firefox', 'firefox.exe'],
    },
    'webkit': {
        'ubuntu18.04': ['pw_run.sh'],
        'ubuntu20.04': ['pw_run.sh'],
        'mac10.13': undefined,
        'mac10.14': ['pw_run.sh'],
        'mac10.15': ['pw_run.sh'],
        'mac11': ['pw_run.sh'],
        'mac11-arm64': ['pw_run.sh'],
        'win32': ['Playwright.exe'],
        'win64': ['Playwright.exe'],
    },
    'webkit-technology-preview': {
        'ubuntu18.04': ['pw_run.sh'],
        'ubuntu20.04': ['pw_run.sh'],
        'mac10.13': undefined,
        'mac10.14': ['pw_run.sh'],
        'mac10.15': ['pw_run.sh'],
        'mac11': ['pw_run.sh'],
        'mac11-arm64': ['pw_run.sh'],
        'win32': ['Playwright.exe'],
        'win64': ['Playwright.exe'],
    },
    'ffmpeg': {
        'ubuntu18.04': ['ffmpeg-linux'],
        'ubuntu20.04': ['ffmpeg-linux'],
        'mac10.13': ['ffmpeg-mac'],
        'mac10.14': ['ffmpeg-mac'],
        'mac10.15': ['ffmpeg-mac'],
        'mac11': ['ffmpeg-mac'],
        'mac11-arm64': ['ffmpeg-mac'],
        'win32': ['ffmpeg-win32.exe'],
        'win64': ['ffmpeg-win64.exe'],
    },
};
const DOWNLOAD_URLS = {
    'chromium': {
        'ubuntu18.04': '%s/builds/chromium/%s/chromium-linux.zip',
        'ubuntu20.04': '%s/builds/chromium/%s/chromium-linux.zip',
        'mac10.13': '%s/builds/chromium/%s/chromium-mac.zip',
        'mac10.14': '%s/builds/chromium/%s/chromium-mac.zip',
        'mac10.15': '%s/builds/chromium/%s/chromium-mac.zip',
        'mac11': '%s/builds/chromium/%s/chromium-mac.zip',
        'mac11-arm64': '%s/builds/chromium/%s/chromium-mac-arm64.zip',
        'win32': '%s/builds/chromium/%s/chromium-win32.zip',
        'win64': '%s/builds/chromium/%s/chromium-win64.zip',
    },
    'firefox': {
        'ubuntu18.04': '%s/builds/firefox/%s/firefox-ubuntu-18.04.zip',
        'ubuntu20.04': '%s/builds/firefox/%s/firefox-ubuntu-20.04.zip',
        'mac10.13': '%s/builds/firefox/%s/firefox-mac-10.14.zip',
        'mac10.14': '%s/builds/firefox/%s/firefox-mac-10.14.zip',
        'mac10.15': '%s/builds/firefox/%s/firefox-mac-10.14.zip',
        'mac11': '%s/builds/firefox/%s/firefox-mac-10.14.zip',
        'mac11-arm64': '%s/builds/firefox/%s/firefox-mac-11.0-arm64.zip',
        'win32': '%s/builds/firefox/%s/firefox-win32.zip',
        'win64': '%s/builds/firefox/%s/firefox-win64.zip',
    },
    'firefox-beta': {
        'ubuntu18.04': '%s/builds/firefox-beta/%s/firefox-beta-ubuntu-18.04.zip',
        'ubuntu20.04': '%s/builds/firefox-beta/%s/firefox-beta-ubuntu-20.04.zip',
        'mac10.13': '%s/builds/firefox-beta/%s/firefox-beta-mac-10.14.zip',
        'mac10.14': '%s/builds/firefox-beta/%s/firefox-beta-mac-10.14.zip',
        'mac10.15': '%s/builds/firefox-beta/%s/firefox-beta-mac-10.14.zip',
        'mac11': '%s/builds/firefox-beta/%s/firefox-beta-mac-10.14.zip',
        'mac11-arm64': '%s/builds/firefox-beta/%s/firefox-beta-mac-11.0-arm64.zip',
        'win32': '%s/builds/firefox-beta/%s/firefox-beta-win32.zip',
        'win64': '%s/builds/firefox-beta/%s/firefox-beta-win64.zip',
    },
    'webkit': {
        'ubuntu18.04': '%s/builds/webkit/%s/webkit-ubuntu-18.04.zip',
        'ubuntu20.04': '%s/builds/webkit/%s/webkit-ubuntu-20.04.zip',
        'mac10.13': undefined,
        'mac10.14': '%s/builds/deprecated-webkit-mac-10.14/%s/deprecated-webkit-mac-10.14.zip',
        'mac10.15': '%s/builds/webkit/%s/webkit-mac-10.15.zip',
        'mac11': '%s/builds/webkit/%s/webkit-mac-10.15.zip',
        'mac11-arm64': '%s/builds/webkit/%s/webkit-mac-11.0-arm64.zip',
        'win32': '%s/builds/webkit/%s/webkit-win64.zip',
        'win64': '%s/builds/webkit/%s/webkit-win64.zip',
    },
    'webkit-technology-preview': {
        'ubuntu18.04': '%s/builds/webkit/%s/webkit-ubuntu-18.04.zip',
        'ubuntu20.04': '%s/builds/webkit/%s/webkit-ubuntu-20.04.zip',
        'mac10.13': undefined,
        'mac10.14': undefined,
        'mac10.15': '%s/builds/webkit/%s/webkit-mac-10.15.zip',
        'mac11': '%s/builds/webkit/%s/webkit-mac-10.15.zip',
        'mac11-arm64': '%s/builds/webkit/%s/webkit-mac-11.0-arm64.zip',
        'win32': '%s/builds/webkit/%s/webkit-win64.zip',
        'win64': '%s/builds/webkit/%s/webkit-win64.zip',
    },
    'ffmpeg': {
        'ubuntu18.04': '%s/builds/ffmpeg/%s/ffmpeg-linux.zip',
        'ubuntu20.04': '%s/builds/ffmpeg/%s/ffmpeg-linux.zip',
        'mac10.13': '%s/builds/ffmpeg/%s/ffmpeg-mac.zip',
        'mac10.14': '%s/builds/ffmpeg/%s/ffmpeg-mac.zip',
        'mac10.15': '%s/builds/ffmpeg/%s/ffmpeg-mac.zip',
        'mac11': '%s/builds/ffmpeg/%s/ffmpeg-mac.zip',
        'mac11-arm64': '%s/builds/ffmpeg/%s/ffmpeg-mac.zip',
        'win32': '%s/builds/ffmpeg/%s/ffmpeg-win32.zip',
        'win64': '%s/builds/ffmpeg/%s/ffmpeg-win64.zip',
    },
};
exports.hostPlatform = (() => {
    const platform = os.platform();
    if (platform === 'darwin') {
        const [major, minor] = child_process_1.execSync('sw_vers -productVersion', {
            stdio: ['ignore', 'pipe', 'ignore']
        }).toString('utf8').trim().split('.').map(x => parseInt(x, 10));
        let arm64 = false;
        // BigSur is the first version that might run on Apple Silicon.
        if (major >= 11) {
            arm64 = child_process_1.execSync('/usr/sbin/sysctl -in hw.optional.arm64', {
                stdio: ['ignore', 'pipe', 'ignore']
            }).toString().trim() === '1';
        }
        // We do not want to differentiate between minor big sur releases
        // since they don't change core APIs so far.
        const macVersion = major === 10 ? `${major}.${minor}` : `${major}`;
        const archSuffix = arm64 ? '-arm64' : '';
        return `mac${macVersion}${archSuffix}`;
    }
    if (platform === 'linux') {
        const ubuntuVersion = ubuntuVersion_1.getUbuntuVersionSync();
        if (parseInt(ubuntuVersion, 10) <= 19)
            return 'ubuntu18.04';
        return 'ubuntu20.04';
    }
    if (platform === 'win32')
        return os.arch() === 'x64' ? 'win64' : 'win32';
    return platform;
})();
exports.registryDirectory = (() => {
    let result;
    const envDefined = utils_1.getFromENV('PLAYWRIGHT_BROWSERS_PATH');
    if (envDefined === '0') {
        result = path_1.default.join(__dirname, '..', '..', '.local-browsers');
    }
    else if (envDefined) {
        result = envDefined;
    }
    else {
        let cacheDirectory;
        if (process.platform === 'linux')
            cacheDirectory = process.env.XDG_CACHE_HOME || path_1.default.join(os.homedir(), '.cache');
        else if (process.platform === 'darwin')
            cacheDirectory = path_1.default.join(os.homedir(), 'Library', 'Caches');
        else if (process.platform === 'win32')
            cacheDirectory = process.env.LOCALAPPDATA || path_1.default.join(os.homedir(), 'AppData', 'Local');
        else
            throw new Error('Unsupported platform: ' + process.platform);
        result = path_1.default.join(cacheDirectory, 'ms-playwright');
    }
    if (!path_1.default.isAbsolute(result)) {
        // It is important to resolve to the absolute path:
        //   - for unzipping to work correctly;
        //   - so that registry directory matches between installation and execution.
        // INIT_CWD points to the root of `npm/yarn install` and is probably what
        // the user meant when typing the relative path.
        result = path_1.default.resolve(utils_1.getFromENV('INIT_CWD') || process.cwd(), result);
    }
    return result;
})();
function isBrowserDirectory(browserDirectory) {
    const baseName = path_1.default.basename(browserDirectory);
    for (const browserName of exports.allBrowserNames) {
        if (baseName.startsWith(browserName + '-'))
            return true;
    }
    return false;
}
exports.isBrowserDirectory = isBrowserDirectory;
let currentPackageRegistry = undefined;
class Registry {
    constructor(packagePath) {
        // require() needs to be used there otherwise it breaks on Vercel serverless
        // functions. See https://github.com/microsoft/playwright/pull/6186
        const browsersJSON = require(path_1.default.join(packagePath, 'browsers.json'));
        this._descriptors = browsersJSON['browsers'].map((obj) => {
            const name = obj.name;
            const revisionOverride = (obj.revisionOverrides || {})[exports.hostPlatform];
            const revision = revisionOverride || obj.revision;
            const browserDirectoryPrefix = revisionOverride ? `${name}_${exports.hostPlatform}_special` : `${name}`;
            return {
                name,
                revision,
                installByDefault: !!obj.installByDefault,
                // Method `isBrowserDirectory` determines directory to be browser iff
                // it starts with some browser name followed by '-'. Some browser names
                // are prefixes of others, e.g. 'webkit' is a prefix of `webkit-technology-preview`.
                // To avoid older registries erroneously removing 'webkit-technology-preview', we have to
                // ensure that browser folders to never include dashes inside.
                browserDirectory: browserDirectoryPrefix.replace(/-/g, '_') + '-' + revision,
            };
        });
    }
    static currentPackageRegistry() {
        if (!currentPackageRegistry)
            currentPackageRegistry = new Registry(PACKAGE_PATH);
        return currentPackageRegistry;
    }
    browserDirectory(browserName) {
        const browser = this._descriptors.find(browser => browser.name === browserName);
        utils_1.assert(browser, `ERROR: Playwright does not support ${browserName}`);
        return path_1.default.join(exports.registryDirectory, browser.browserDirectory);
    }
    revision(browserName) {
        const browser = this._descriptors.find(browser => browser.name === browserName);
        utils_1.assert(browser, `ERROR: Playwright does not support ${browserName}`);
        return parseInt(browser.revision, 10);
    }
    linuxLddDirectories(browserName) {
        const browserDirectory = this.browserDirectory(browserName);
        switch (browserName) {
            case 'chromium':
                return [path_1.default.join(browserDirectory, 'chrome-linux')];
            case 'webkit':
            case 'webkit-technology-preview':
                return [
                    path_1.default.join(browserDirectory, 'minibrowser-gtk'),
                    path_1.default.join(browserDirectory, 'minibrowser-gtk', 'bin'),
                    path_1.default.join(browserDirectory, 'minibrowser-gtk', 'lib'),
                    path_1.default.join(browserDirectory, 'minibrowser-wpe'),
                    path_1.default.join(browserDirectory, 'minibrowser-wpe', 'bin'),
                    path_1.default.join(browserDirectory, 'minibrowser-wpe', 'lib'),
                ];
            case 'firefox':
            case 'firefox-beta':
                return [path_1.default.join(browserDirectory, 'firefox')];
            default:
                return [];
        }
    }
    windowsExeAndDllDirectories(browserName) {
        const browserDirectory = this.browserDirectory(browserName);
        if (browserName === 'chromium')
            return [path_1.default.join(browserDirectory, 'chrome-win')];
        if (browserName === 'firefox')
            return [path_1.default.join(browserDirectory, 'firefox')];
        if (browserName === 'webkit')
            return [browserDirectory];
        return [];
    }
    executablePath(browserName) {
        const browserDirectory = this.browserDirectory(browserName);
        const tokens = EXECUTABLE_PATHS[browserName][exports.hostPlatform];
        return tokens ? path_1.default.join(browserDirectory, ...tokens) : undefined;
    }
    downloadURL(browserName) {
        const browser = this._descriptors.find(browser => browser.name === browserName);
        utils_1.assert(browser, `ERROR: Playwright does not support ${browserName}`);
        const envDownloadHost = {
            'chromium': 'PLAYWRIGHT_CHROMIUM_DOWNLOAD_HOST',
            'firefox': 'PLAYWRIGHT_FIREFOX_DOWNLOAD_HOST',
            'firefox-beta': 'PLAYWRIGHT_FIREFOX_DOWNLOAD_HOST',
            'webkit': 'PLAYWRIGHT_WEBKIT_DOWNLOAD_HOST',
            'webkit-technology-preview': 'PLAYWRIGHT_WEBKIT_DOWNLOAD_HOST',
            'ffmpeg': 'PLAYWRIGHT_FFMPEG_DOWNLOAD_HOST',
        };
        const downloadHost = utils_1.getFromENV(envDownloadHost[browserName]) ||
            utils_1.getFromENV('PLAYWRIGHT_DOWNLOAD_HOST') ||
            'https://playwright.azureedge.net';
        const urlTemplate = DOWNLOAD_URLS[browserName][exports.hostPlatform];
        utils_1.assert(urlTemplate, `ERROR: Playwright does not support ${browserName} on ${exports.hostPlatform}`);
        return util.format(urlTemplate, downloadHost, browser.revision);
    }
    isSupportedBrowser(browserName) {
        // We retain browsers if they are found in the descriptor.
        // Note, however, that there are older versions out in the wild that rely on
        // the "download" field in the browser descriptor and use its value
        // to retain and download browsers.
        // As of v1.10, we decided to abandon "download" field.
        return this._descriptors.some(browser => browser.name === browserName);
    }
    installByDefault() {
        return this._descriptors.filter(browser => browser.installByDefault).map(browser => browser.name);
    }
}
exports.Registry = Registry;
//# sourceMappingURL=registry.js.map