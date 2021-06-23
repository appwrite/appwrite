"use strict";
/**
 * Copyright Microsoft Corporation. All rights reserved.
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
exports.installBrowsersWithProgressBar = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const proper_lockfile_1 = __importDefault(require("proper-lockfile"));
const registry_1 = require("../utils/registry");
const browserFetcher = __importStar(require("./browserFetcher"));
const utils_1 = require("../utils/utils");
const fsExistsAsync = (filePath) => fs_1.default.promises.readFile(filePath).then(() => true).catch(e => false);
const PACKAGE_PATH = path_1.default.join(__dirname, '..', '..');
async function installBrowsersWithProgressBar(browserNames = registry_1.Registry.currentPackageRegistry().installByDefault()) {
    // PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD should have a value of 0 or 1
    if (utils_1.getAsBooleanFromENV('PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD')) {
        browserFetcher.logPolitely('Skipping browsers download because `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD` env variable is set');
        return false;
    }
    await fs_1.default.promises.mkdir(registry_1.registryDirectory, { recursive: true });
    const lockfilePath = path_1.default.join(registry_1.registryDirectory, '__dirlock');
    const releaseLock = await proper_lockfile_1.default.lock(registry_1.registryDirectory, {
        retries: {
            retries: 10,
            // Retry 20 times during 10 minutes with
            // exponential back-off.
            // See documentation at: https://www.npmjs.com/package/retry#retrytimeoutsoptions
            factor: 1.27579,
        },
        onCompromised: (err) => {
            throw new Error(`${err.message} Path: ${lockfilePath}`);
        },
        lockfilePath,
    });
    const linksDir = path_1.default.join(registry_1.registryDirectory, '.links');
    try {
        await fs_1.default.promises.mkdir(linksDir, { recursive: true });
        await fs_1.default.promises.writeFile(path_1.default.join(linksDir, utils_1.calculateSha1(PACKAGE_PATH)), PACKAGE_PATH);
        await validateCache(linksDir, browserNames);
    }
    finally {
        await releaseLock();
    }
}
exports.installBrowsersWithProgressBar = installBrowsersWithProgressBar;
async function validateCache(linksDir, browserNames) {
    // 1. Collect used downloads and package descriptors.
    const usedBrowserPaths = new Set();
    for (const fileName of await fs_1.default.promises.readdir(linksDir)) {
        const linkPath = path_1.default.join(linksDir, fileName);
        let linkTarget = '';
        try {
            linkTarget = (await fs_1.default.promises.readFile(linkPath)).toString();
            const linkRegistry = new registry_1.Registry(linkTarget);
            for (const browserName of registry_1.allBrowserNames) {
                if (!linkRegistry.isSupportedBrowser(browserName))
                    continue;
                const usedBrowserPath = linkRegistry.browserDirectory(browserName);
                const browserRevision = linkRegistry.revision(browserName);
                // Old browser installations don't have marker file.
                const shouldHaveMarkerFile = (browserName === 'chromium' && browserRevision >= 786218) ||
                    (browserName === 'firefox' && browserRevision >= 1128) ||
                    (browserName === 'webkit' && browserRevision >= 1307) ||
                    // All new applications have a marker file right away.
                    (browserName !== 'firefox' && browserName !== 'chromium' && browserName !== 'webkit');
                if (!shouldHaveMarkerFile || (await fsExistsAsync(markerFilePath(usedBrowserPath))))
                    usedBrowserPaths.add(usedBrowserPath);
            }
        }
        catch (e) {
            await fs_1.default.promises.unlink(linkPath).catch(e => { });
        }
    }
    // 2. Delete all unused browsers.
    if (!utils_1.getAsBooleanFromENV('PLAYWRIGHT_SKIP_BROWSER_GC')) {
        let downloadedBrowsers = (await fs_1.default.promises.readdir(registry_1.registryDirectory)).map(file => path_1.default.join(registry_1.registryDirectory, file));
        downloadedBrowsers = downloadedBrowsers.filter(file => registry_1.isBrowserDirectory(file));
        const directories = new Set(downloadedBrowsers);
        for (const browserDirectory of usedBrowserPaths)
            directories.delete(browserDirectory);
        for (const directory of directories)
            browserFetcher.logPolitely('Removing unused browser at ' + directory);
        await utils_1.removeFolders([...directories]);
    }
    // 3. Install missing browsers for this package.
    const myRegistry = registry_1.Registry.currentPackageRegistry();
    for (const browserName of browserNames) {
        await browserFetcher.downloadBrowserWithProgressBar(myRegistry, browserName).catch(e => {
            throw new Error(`Failed to download ${browserName}, caused by\n${e.stack}`);
        });
        await fs_1.default.promises.writeFile(markerFilePath(myRegistry.browserDirectory(browserName)), '');
    }
}
function markerFilePath(browserDirectory) {
    return path_1.default.join(browserDirectory, 'INSTALLATION_COMPLETE');
}
//# sourceMappingURL=installer.js.map