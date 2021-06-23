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
exports.BrowserType = void 0;
const fs_1 = __importDefault(require("fs"));
const os = __importStar(require("os"));
const path_1 = __importDefault(require("path"));
const browserContext_1 = require("./browserContext");
const transport_1 = require("./transport");
const processLauncher_1 = require("./processLauncher");
const pipeTransport_1 = require("./pipeTransport");
const progress_1 = require("./progress");
const timeoutSettings_1 = require("../utils/timeoutSettings");
const validateDependencies_1 = require("./validateDependencies");
const utils_1 = require("../utils/utils");
const helper_1 = require("./helper");
const debugLogger_1 = require("../utils/debugLogger");
const instrumentation_1 = require("./instrumentation");
const ARTIFACTS_FOLDER = path_1.default.join(os.tmpdir(), 'playwright-artifacts-');
class BrowserType extends instrumentation_1.SdkObject {
    constructor(browserName, playwrightOptions) {
        super(playwrightOptions.rootSdkObject, 'browser-type');
        this.attribution.browserType = this;
        this._playwrightOptions = playwrightOptions;
        this._name = browserName;
        this._registry = playwrightOptions.registry;
    }
    executablePath(channel) {
        return this._registry.executablePath(this._name) || '';
    }
    name() {
        return this._name;
    }
    async launch(metadata, options, protocolLogger) {
        var _a, _b;
        options = validateLaunchOptions(options, (_b = (_a = this._playwrightOptions).loopbackProxyOverride) === null || _b === void 0 ? void 0 : _b.call(_a));
        const controller = new progress_1.ProgressController(metadata, this);
        controller.setLogName('browser');
        const browser = await controller.run(progress => {
            return this._innerLaunchWithRetries(progress, options, undefined, helper_1.helper.debugProtocolLogger(protocolLogger)).catch(e => { throw this._rewriteStartupError(e); });
        }, timeoutSettings_1.TimeoutSettings.timeout(options));
        return browser;
    }
    async launchPersistentContext(metadata, userDataDir, options) {
        var _a, _b;
        options = validateLaunchOptions(options, (_b = (_a = this._playwrightOptions).loopbackProxyOverride) === null || _b === void 0 ? void 0 : _b.call(_a));
        const controller = new progress_1.ProgressController(metadata, this);
        const persistent = options;
        controller.setLogName('browser');
        const browser = await controller.run(progress => {
            return this._innerLaunchWithRetries(progress, options, persistent, helper_1.helper.debugProtocolLogger(), userDataDir).catch(e => { throw this._rewriteStartupError(e); });
        }, timeoutSettings_1.TimeoutSettings.timeout(options));
        return browser._defaultContext;
    }
    async _innerLaunchWithRetries(progress, options, persistent, protocolLogger, userDataDir) {
        try {
            return this._innerLaunch(progress, options, persistent, protocolLogger, userDataDir);
        }
        catch (error) {
            // @see https://github.com/microsoft/playwright/issues/5214
            const errorMessage = typeof error === 'object' && typeof error.message === 'string' ? error.message : '';
            if (errorMessage.includes('Inconsistency detected by ld.so')) {
                progress.log(`<restarting browser due to hitting race condition in glibc>`);
                return this._innerLaunch(progress, options, persistent, protocolLogger, userDataDir);
            }
            throw error;
        }
    }
    async _innerLaunch(progress, options, persistent, protocolLogger, userDataDir) {
        options.proxy = options.proxy ? browserContext_1.normalizeProxySettings(options.proxy) : undefined;
        const browserLogsCollector = new debugLogger_1.RecentLogsCollector();
        const { browserProcess, artifactsDir, transport } = await this._launchProcess(progress, options, !!persistent, browserLogsCollector, userDataDir);
        if (options.__testHookBeforeCreateBrowser)
            await options.__testHookBeforeCreateBrowser();
        const browserOptions = {
            ...this._playwrightOptions,
            name: this._name,
            isChromium: this._name === 'chromium',
            channel: options.channel,
            slowMo: options.slowMo,
            persistent,
            headful: !options.headless,
            artifactsDir,
            downloadsPath: (options.downloadsPath || artifactsDir),
            tracesDir: (options.tracesDir || artifactsDir),
            browserProcess,
            customExecutablePath: options.executablePath,
            proxy: options.proxy,
            protocolLogger,
            browserLogsCollector,
            wsEndpoint: options.useWebSocket ? transport.wsEndpoint : undefined,
        };
        if (persistent)
            browserContext_1.validateBrowserContextOptions(persistent, browserOptions);
        copyTestHooks(options, browserOptions);
        const browser = await this._connectToTransport(transport, browserOptions);
        // We assume no control when using custom arguments, and do not prepare the default context in that case.
        if (persistent && !options.ignoreAllDefaultArgs)
            await browser._defaultContext._loadDefaultContext(progress);
        return browser;
    }
    async _launchProcess(progress, options, isPersistent, browserLogsCollector, userDataDir) {
        var _a;
        const { ignoreDefaultArgs, ignoreAllDefaultArgs, args = [], executablePath = null, handleSIGINT = true, handleSIGTERM = true, handleSIGHUP = true, } = options;
        const env = options.env ? processLauncher_1.envArrayToObject(options.env) : process.env;
        const tempDirectories = [];
        if (options.downloadsPath)
            await fs_1.default.promises.mkdir(options.downloadsPath, { recursive: true });
        if (options.tracesDir)
            await fs_1.default.promises.mkdir(options.tracesDir, { recursive: true });
        const artifactsDir = await fs_1.default.promises.mkdtemp(ARTIFACTS_FOLDER);
        tempDirectories.push(artifactsDir);
        if (!userDataDir) {
            userDataDir = await fs_1.default.promises.mkdtemp(path_1.default.join(os.tmpdir(), `playwright_${this._name}dev_profile-`));
            tempDirectories.push(userDataDir);
        }
        const browserArguments = [];
        if (ignoreAllDefaultArgs)
            browserArguments.push(...args);
        else if (ignoreDefaultArgs)
            browserArguments.push(...this._defaultArgs(options, isPersistent, userDataDir).filter(arg => ignoreDefaultArgs.indexOf(arg) === -1));
        else
            browserArguments.push(...this._defaultArgs(options, isPersistent, userDataDir));
        const executable = executablePath || this.executablePath(options.channel);
        if (!executable)
            throw new Error(`No executable path is specified. Pass "executablePath" option directly.`);
        if (!(await utils_1.existsAsync(executable))) {
            const errorMessageLines = [`Failed to launch ${this._name} because executable doesn't exist at ${executable}`];
            // If we tried using stock downloaded browser, suggest re-installing playwright.
            if (!executablePath)
                errorMessageLines.push(`Try re-installing playwright with "npm install playwright"`);
            throw new Error(errorMessageLines.join('\n'));
        }
        // Only validate dependencies for downloadable browsers.
        if (!executablePath && !options.channel)
            await validateDependencies_1.validateHostRequirements(this._registry, this._name);
        else if (!executablePath && options.channel && this._registry.isSupportedBrowser(options.channel))
            await validateDependencies_1.validateHostRequirements(this._registry, options.channel);
        let wsEndpointCallback;
        const shouldWaitForWSListening = options.useWebSocket || ((_a = options.args) === null || _a === void 0 ? void 0 : _a.some(a => a.startsWith('--remote-debugging-port')));
        const waitForWSEndpoint = shouldWaitForWSListening ? new Promise(f => wsEndpointCallback = f) : undefined;
        // Note: it is important to define these variables before launchProcess, so that we don't get
        // "Cannot access 'browserServer' before initialization" if something went wrong.
        let transport = undefined;
        let browserProcess = undefined;
        const { launchedProcess, gracefullyClose, kill } = await processLauncher_1.launchProcess({
            executablePath: executable,
            args: browserArguments,
            env: this._amendEnvironment(env, userDataDir, executable, browserArguments),
            handleSIGINT,
            handleSIGTERM,
            handleSIGHUP,
            log: (message) => {
                if (wsEndpointCallback) {
                    const match = message.match(/DevTools listening on (.*)/);
                    if (match)
                        wsEndpointCallback(match[1]);
                }
                progress.log(message);
                browserLogsCollector.log(message);
            },
            stdio: 'pipe',
            tempDirectories,
            attemptToGracefullyClose: async () => {
                if (options.__testHookGracefullyClose)
                    await options.__testHookGracefullyClose();
                // We try to gracefully close to prevent crash reporting and core dumps.
                // Note that it's fine to reuse the pipe transport, since
                // our connection ignores kBrowserCloseMessageId.
                this._attemptToGracefullyCloseBrowser(transport);
            },
            onExit: (exitCode, signal) => {
                if (browserProcess && browserProcess.onclose)
                    browserProcess.onclose(exitCode, signal);
            },
        });
        async function closeOrKill(timeout) {
            let timer;
            try {
                await Promise.race([
                    gracefullyClose(),
                    new Promise((resolve, reject) => timer = setTimeout(reject, timeout)),
                ]);
            }
            catch (ignored) {
                await kill().catch(ignored => { }); // Make sure to await actual process exit.
            }
            finally {
                clearTimeout(timer);
            }
        }
        browserProcess = {
            onclose: undefined,
            process: launchedProcess,
            close: () => closeOrKill(options.__testHookBrowserCloseTimeout || timeoutSettings_1.DEFAULT_TIMEOUT),
            kill
        };
        progress.cleanupWhenAborted(() => closeOrKill(progress.timeUntilDeadline()));
        let wsEndpoint;
        if (shouldWaitForWSListening)
            wsEndpoint = await waitForWSEndpoint;
        if (options.useWebSocket) {
            transport = await transport_1.WebSocketTransport.connect(progress, wsEndpoint);
        }
        else {
            const stdio = launchedProcess.stdio;
            transport = new pipeTransport_1.PipeTransport(stdio[3], stdio[4]);
        }
        return { browserProcess, artifactsDir, transport };
    }
    async connectOverCDP(metadata, endpointURL, options, timeout) {
        throw new Error('CDP connections are only supported by Chromium');
    }
}
exports.BrowserType = BrowserType;
function copyTestHooks(from, to) {
    for (const [key, value] of Object.entries(from)) {
        if (key.startsWith('__testHook'))
            to[key] = value;
    }
}
function validateLaunchOptions(options, proxyOverride) {
    const { devtools = false } = options;
    let { headless = !devtools, downloadsPath, proxy } = options;
    if (utils_1.debugMode())
        headless = false;
    if (downloadsPath && !path_1.default.isAbsolute(downloadsPath))
        downloadsPath = path_1.default.join(process.cwd(), downloadsPath);
    if (proxyOverride)
        proxy = { server: proxyOverride };
    return { ...options, devtools, headless, downloadsPath, proxy };
}
//# sourceMappingURL=browserType.js.map