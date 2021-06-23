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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.logPolitely = exports.downloadBrowserWithProgressBar = void 0;
const extract_zip_1 = __importDefault(require("extract-zip"));
const fs_1 = __importDefault(require("fs"));
const os_1 = __importDefault(require("os"));
const path_1 = __importDefault(require("path"));
const progress_1 = __importDefault(require("progress"));
const registry_1 = require("../utils/registry");
const utils_1 = require("../utils/utils");
const debugLogger_1 = require("../utils/debugLogger");
async function downloadBrowserWithProgressBar(registry, browserName) {
    const browserDirectory = registry.browserDirectory(browserName);
    const progressBarName = `Playwright build of ${browserName} v${registry.revision(browserName)}`;
    if (await utils_1.existsAsync(browserDirectory)) {
        // Already downloaded.
        debugLogger_1.debugLogger.log('install', `browser ${browserName} is already downloaded.`);
        return false;
    }
    let progressBar;
    let lastDownloadedBytes = 0;
    function progress(downloadedBytes, totalBytes) {
        if (!process.stderr.isTTY)
            return;
        if (!progressBar) {
            progressBar = new progress_1.default(`Downloading ${progressBarName} - ${toMegabytes(totalBytes)} [:bar] :percent :etas `, {
                complete: '=',
                incomplete: ' ',
                width: 20,
                total: totalBytes,
            });
        }
        const delta = downloadedBytes - lastDownloadedBytes;
        lastDownloadedBytes = downloadedBytes;
        progressBar.tick(delta);
    }
    const url = registry.downloadURL(browserName);
    const zipPath = path_1.default.join(os_1.default.tmpdir(), `playwright-download-${browserName}-${registry_1.hostPlatform}-${registry.revision(browserName)}.zip`);
    try {
        for (let attempt = 1, N = 3; attempt <= N; ++attempt) {
            debugLogger_1.debugLogger.log('install', `downloading ${progressBarName} - attempt #${attempt}`);
            const { error } = await utils_1.downloadFile(url, zipPath, { progressCallback: progress, log: debugLogger_1.debugLogger.log.bind(debugLogger_1.debugLogger, 'install') });
            if (!error) {
                debugLogger_1.debugLogger.log('install', `SUCCESS downloading ${progressBarName}`);
                break;
            }
            const errorMessage = typeof error === 'object' && typeof error.message === 'string' ? error.message : '';
            debugLogger_1.debugLogger.log('install', `attempt #${attempt} - ERROR: ${errorMessage}`);
            if (attempt < N && (errorMessage.includes('ECONNRESET') || errorMessage.includes('ETIMEDOUT'))) {
                // Maximum delay is 3rd retry: 1337.5ms
                const millis = (Math.random() * 200) + (250 * Math.pow(1.5, attempt));
                debugLogger_1.debugLogger.log('install', `sleeping ${millis}ms before retry...`);
                await new Promise(c => setTimeout(c, millis));
            }
            else {
                throw error;
            }
        }
        debugLogger_1.debugLogger.log('install', `extracting archive`);
        debugLogger_1.debugLogger.log('install', `-- zip: ${zipPath}`);
        debugLogger_1.debugLogger.log('install', `-- location: ${browserDirectory}`);
        await extract_zip_1.default(zipPath, { dir: browserDirectory });
        const executablePath = registry.executablePath(browserName);
        debugLogger_1.debugLogger.log('install', `fixing permissions at ${executablePath}`);
        await fs_1.default.promises.chmod(executablePath, 0o755);
    }
    catch (e) {
        debugLogger_1.debugLogger.log('install', `FAILED installation ${progressBarName} with error: ${e}`);
        process.exitCode = 1;
        throw e;
    }
    finally {
        if (await utils_1.existsAsync(zipPath))
            await fs_1.default.promises.unlink(zipPath);
    }
    logPolitely(`${progressBarName} downloaded to ${browserDirectory}`);
    return true;
}
exports.downloadBrowserWithProgressBar = downloadBrowserWithProgressBar;
function toMegabytes(bytes) {
    const mb = bytes / 1024 / 1024;
    return `${Math.round(mb * 10) / 10} Mb`;
}
function logPolitely(toBeLogged) {
    const logLevel = process.env.npm_config_loglevel;
    const logLevelDisplay = ['silent', 'error', 'warn'].indexOf(logLevel || '') > -1;
    if (!logLevelDisplay)
        console.log(toBeLogged); // eslint-disable-line no-console
}
exports.logPolitely = logPolitely;
//# sourceMappingURL=browserFetcher.js.map