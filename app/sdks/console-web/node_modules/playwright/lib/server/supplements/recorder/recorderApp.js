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
exports.RecorderApp = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const progress_1 = require("../../progress");
const events_1 = require("events");
const instrumentation_1 = require("../../instrumentation");
const utils_1 = require("../../../utils/utils");
class RecorderApp extends events_1.EventEmitter {
    constructor(page, wsEndpoint) {
        super();
        this.setMaxListeners(0);
        this._page = page;
        this.wsEndpoint = wsEndpoint;
    }
    async close() {
        await this._page.context().close(instrumentation_1.internalCallMetadata());
    }
    async _init() {
        const icon = await fs_1.default.promises.readFile(require.resolve('../../../web/recorder/app_icon.png'));
        const crPopup = this._page._delegate;
        await crPopup._mainFrameSession._client.send('Browser.setDockTile', {
            image: icon.toString('base64')
        });
        await this._page._setServerRequestInterceptor(async (route) => {
            if (route.request().url().startsWith('https://playwright/')) {
                const uri = route.request().url().substring('https://playwright/'.length);
                const file = require.resolve('../../../web/recorder/' + uri);
                const buffer = await fs_1.default.promises.readFile(file);
                await route.fulfill({
                    status: 200,
                    headers: [
                        { name: 'Content-Type', value: extensionToMime[path_1.default.extname(file)] }
                    ],
                    body: buffer.toString('base64'),
                    isBase64: true
                });
                return;
            }
            await route.continue();
        });
        await this._page.exposeBinding('dispatch', false, (_, data) => this.emit('event', data));
        this._page.once('close', () => {
            this.emit('close');
            this._page.context().close(instrumentation_1.internalCallMetadata()).catch(e => console.error(e));
        });
        const mainFrame = this._page.mainFrame();
        await mainFrame.goto(instrumentation_1.internalCallMetadata(), 'https://playwright/index.html');
    }
    static async open(inspectedContext) {
        const recorderPlaywright = require('../../playwright').createPlaywright(true);
        const args = [
            '--app=data:text/html,',
            '--window-size=600,600',
            '--window-position=1280,10',
        ];
        if (process.env.PWTEST_RECORDER_PORT)
            args.push(`--remote-debugging-port=${process.env.PWTEST_RECORDER_PORT}`);
        let channel;
        let executablePath;
        if (inspectedContext._browser.options.isChromium) {
            channel = inspectedContext._browser.options.channel;
            const defaultExecutablePath = recorderPlaywright.chromium.executablePath(channel);
            if (!(await utils_1.existsAsync(defaultExecutablePath)))
                executablePath = inspectedContext._browser.options.customExecutablePath;
        }
        const context = await recorderPlaywright.chromium.launchPersistentContext(instrumentation_1.internalCallMetadata(), '', {
            channel,
            executablePath,
            sdkLanguage: inspectedContext._options.sdkLanguage,
            args,
            noDefaultViewport: true,
            headless: !!process.env.PWTEST_CLI_HEADLESS || (utils_1.isUnderTest() && !inspectedContext._browser.options.headful),
            useWebSocket: !!process.env.PWTEST_RECORDER_PORT
        });
        const controller = new progress_1.ProgressController(instrumentation_1.internalCallMetadata(), context._browser);
        await controller.run(async (progress) => {
            await context._browser._defaultContext._loadDefaultContextAsIs(progress);
        });
        const [page] = context.pages();
        const result = new RecorderApp(page, context._browser.options.wsEndpoint);
        await result._init();
        return result;
    }
    async setMode(mode) {
        await this._page.mainFrame().evaluateExpression(((mode) => {
            window.playwrightSetMode(mode);
        }).toString(), true, mode, 'main').catch(() => { });
    }
    async setFile(file) {
        await this._page.mainFrame().evaluateExpression(((file) => {
            window.playwrightSetFile(file);
        }).toString(), true, file, 'main').catch(() => { });
    }
    async setPaused(paused) {
        await this._page.mainFrame().evaluateExpression(((paused) => {
            window.playwrightSetPaused(paused);
        }).toString(), true, paused, 'main').catch(() => { });
    }
    async setSources(sources) {
        await this._page.mainFrame().evaluateExpression(((sources) => {
            window.playwrightSetSources(sources);
        }).toString(), true, sources, 'main').catch(() => { });
        // Testing harness for runCLI mode.
        {
            if (process.env.PWTEST_CLI_EXIT && sources.length) {
                process.stdout.write('\n-------------8<-------------\n');
                process.stdout.write(sources[0].text);
                process.stdout.write('\n-------------8<-------------\n');
            }
        }
    }
    async setSelector(selector, focus) {
        await this._page.mainFrame().evaluateExpression(((arg) => {
            window.playwrightSetSelector(arg.selector, arg.focus);
        }).toString(), true, { selector, focus }, 'main').catch(() => { });
    }
    async updateCallLogs(callLogs) {
        await this._page.mainFrame().evaluateExpression(((callLogs) => {
            window.playwrightUpdateLogs(callLogs);
        }).toString(), true, callLogs, 'main').catch(() => { });
    }
    async bringToFront() {
        await this._page.bringToFront();
    }
}
exports.RecorderApp = RecorderApp;
const extensionToMime = {
    '.css': 'text/css',
    '.html': 'text/html',
    '.jpeg': 'image/jpeg',
    '.js': 'application/javascript',
    '.png': 'image/png',
    '.ttf': 'font/ttf',
    '.svg': 'image/svg+xml',
    '.webp': 'image/webp',
    '.woff': 'font/woff',
    '.woff2': 'font/woff2',
};
//# sourceMappingURL=recorderApp.js.map