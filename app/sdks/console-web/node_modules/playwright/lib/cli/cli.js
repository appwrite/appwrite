#!/usr/bin/env node
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
exports.showTraceViewer = void 0;
/* eslint-disable no-console */
const extract_zip_1 = __importDefault(require("extract-zip"));
const fs_1 = __importDefault(require("fs"));
const os_1 = __importDefault(require("os"));
const path_1 = __importDefault(require("path"));
const rimraf_1 = __importDefault(require("rimraf"));
const commander_1 = __importDefault(require("commander"));
const driver_1 = require("./driver");
const traceViewer_1 = require("../server/trace/viewer/traceViewer");
const playwright = __importStar(require("../.."));
const child_process_1 = require("child_process");
const installDeps_1 = require("../install/installDeps");
const registry_1 = require("../utils/registry");
const utils = __importStar(require("../utils/utils"));
const SCRIPTS_DIRECTORY = path_1.default.join(__dirname, '..', '..', 'bin');
const allBrowserChannels = new Set(['chrome-beta', 'chrome', 'msedge']);
const packageJSON = require('../../package.json');
const ChannelName = {
    'chrome-beta': 'Google Chrome Beta',
    'chrome': 'Google Chrome',
    'msedge': 'Microsoft Edge',
};
const InstallationScriptName = {
    'chrome-beta': {
        'linux': 'reinstall_chrome_beta_linux.sh',
        'darwin': 'reinstall_chrome_beta_mac.sh',
        'win32': 'reinstall_chrome_beta_win.ps1',
    },
    'chrome': {
        'linux': 'reinstall_chrome_stable_linux.sh',
        'darwin': 'reinstall_chrome_stable_mac.sh',
        'win32': 'reinstall_chrome_stable_win.ps1',
    },
    'msedge': {
        'darwin': 'reinstall_msedge_stable_mac.sh',
        'win32': 'reinstall_msedge_stable_win.ps1',
    },
};
commander_1.default
    .version('Version ' + packageJSON.version)
    .name(process.env.PW_CLI_NAME || 'npx playwright');
commandWithOpenOptions('open [url]', 'open page in browser specified via -b, --browser', [])
    .action(function (url, command) {
    open(command, url, language()).catch(logErrorAndExit);
})
    .on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ open');
    console.log('  $ open -b webkit https://example.com');
});
commandWithOpenOptions('codegen [url]', 'open page and generate code for user actions', [
    ['-o, --output <file name>', 'saves the generated script to a file'],
    ['--target <language>', `language to generate, one of javascript, test, python, python-async, csharp`, language()],
]).action(function (url, command) {
    codegen(command, url, command.target, command.output).catch(logErrorAndExit);
}).on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ codegen');
    console.log('  $ codegen --target=python');
    console.log('  $ codegen -b webkit https://example.com');
});
commander_1.default
    .command('debug <app> [args...]')
    .description('run command in debug mode: disable timeout, open inspector')
    .action(function (app, args) {
    child_process_1.spawn(app, args, {
        env: { ...process.env, PWDEBUG: '1' },
        stdio: 'inherit'
    });
}).on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ debug node test.js');
    console.log('  $ debug npm run test');
});
commander_1.default
    .command('install [browserType...]')
    .description('ensure browsers necessary for this version of Playwright are installed')
    .action(async function (args) {
    try {
        // Install default browsers when invoked without arguments.
        if (!args.length) {
            await driver_1.installBrowsers();
            return;
        }
        const browserNames = new Set(args.filter((browser) => registry_1.allBrowserNames.has(browser)));
        const browserChannels = new Set(args.filter((browser) => allBrowserChannels.has(browser)));
        const faultyArguments = args.filter((browser) => !browserNames.has(browser) && !browserChannels.has(browser));
        if (faultyArguments.length) {
            console.log(`Invalid installation targets: ${faultyArguments.map(name => `'${name}'`).join(', ')}. Expecting one of: ${[...registry_1.allBrowserNames, ...allBrowserChannels].map(name => `'${name}'`).join(', ')}`);
            process.exit(1);
        }
        if (browserNames.has('chromium') || browserChannels.has('chrome-beta') || browserChannels.has('chrome') || browserChannels.has('msedge'))
            browserNames.add('ffmpeg');
        if (browserNames.size)
            await driver_1.installBrowsers([...browserNames]);
        for (const browserChannel of browserChannels)
            await installBrowserChannel(browserChannel);
    }
    catch (e) {
        console.log(`Failed to install browsers\n${e}`);
        process.exit(1);
    }
});
async function installBrowserChannel(channel) {
    const platform = os_1.default.platform();
    const scriptName = InstallationScriptName[channel][platform];
    if (!scriptName)
        throw new Error(`Cannot install ${ChannelName[channel]} on ${platform}`);
    const scriptArgs = [];
    if (channel === 'msedge') {
        const products = JSON.parse(await utils.fetchData('https://edgeupdates.microsoft.com/api/products'));
        const stable = products.find((product) => product.Product === 'Stable');
        if (platform === 'win32') {
            const arch = os_1.default.arch() === 'x64' ? 'x64' : 'x86';
            const release = stable.Releases.find((release) => release.Platform === 'Windows' && release.Architecture === arch);
            const artifact = release.Artifacts.find((artifact) => artifact.ArtifactName === 'msi');
            scriptArgs.push(artifact.Location /* url */);
        }
        else if (platform === 'darwin') {
            const release = stable.Releases.find((release) => release.Platform === 'MacOS' && release.Architecture === 'universal');
            const artifact = release.Artifacts.find((artifact) => artifact.ArtifactName === 'pkg');
            scriptArgs.push(artifact.Location /* url */);
        }
        else {
            throw new Error(`Cannot install ${ChannelName[channel]} on ${platform}`);
        }
    }
    const shell = scriptName.endsWith('.ps1') ? 'powershell.exe' : 'bash';
    const { code } = await utils.spawnAsync(shell, [path_1.default.join(SCRIPTS_DIRECTORY, scriptName), ...scriptArgs], { cwd: SCRIPTS_DIRECTORY, stdio: 'inherit' });
    if (code !== 0)
        throw new Error(`Failed to install ${ChannelName[channel]}`);
}
commander_1.default
    .command('install-deps [browserType...]')
    .description('install dependencies necessary to run browsers (will ask for sudo permissions)')
    .action(async function (browserType) {
    try {
        await installDeps_1.installDeps(browserType);
    }
    catch (e) {
        console.log(`Failed to install browser dependencies\n${e}`);
        process.exit(1);
    }
});
const browsers = [
    { alias: 'cr', name: 'Chromium', type: 'chromium' },
    { alias: 'ff', name: 'Firefox', type: 'firefox' },
    { alias: 'wk', name: 'WebKit', type: 'webkit' },
];
for (const { alias, name, type } of browsers) {
    commandWithOpenOptions(`${alias} [url]`, `open page in ${name}`, [])
        .action(function (url, command) {
        open({ ...command, browser: type }, url, command.target).catch(logErrorAndExit);
    }).on('--help', function () {
        console.log('');
        console.log('Examples:');
        console.log('');
        console.log(`  $ ${alias} https://example.com`);
    });
}
commandWithOpenOptions('screenshot <url> <filename>', 'capture a page screenshot', [
    ['--wait-for-selector <selector>', 'wait for selector before taking a screenshot'],
    ['--wait-for-timeout <timeout>', 'wait for timeout in milliseconds before taking a screenshot'],
    ['--full-page', 'whether to take a full page screenshot (entire scrollable area)'],
]).action(function (url, filename, command) {
    screenshot(command, command, url, filename).catch(logErrorAndExit);
}).on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ screenshot -b webkit https://example.com example.png');
});
commandWithOpenOptions('pdf <url> <filename>', 'save page as pdf', [
    ['--wait-for-selector <selector>', 'wait for given selector before saving as pdf'],
    ['--wait-for-timeout <timeout>', 'wait for given timeout in milliseconds before saving as pdf'],
]).action(function (url, filename, command) {
    pdf(command, command, url, filename).catch(logErrorAndExit);
}).on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ pdf https://example.com example.pdf');
});
commander_1.default
    .command('show-trace [trace]')
    .option('-b, --browser <browserType>', 'browser to use, one of cr, chromium, ff, firefox, wk, webkit', 'chromium')
    .description('Show trace viewer')
    .action(function (trace, command) {
    if (command.browser === 'cr')
        command.browser = 'chromium';
    if (command.browser === 'ff')
        command.browser = 'firefox';
    if (command.browser === 'wk')
        command.browser = 'webkit';
    showTraceViewer(trace, command.browser).catch(logErrorAndExit);
}).on('--help', function () {
    console.log('');
    console.log('Examples:');
    console.log('');
    console.log('  $ show-trace trace/directory');
});
if (!process.env.PW_CLI_TARGET_LANG) {
    let playwrightTestPackagePath = null;
    try {
        const isLocal = packageJSON.name === '@playwright/test' || process.env.PWTEST_CLI_ALLOW_TEST_COMMAND;
        if (isLocal) {
            playwrightTestPackagePath = '../test/cli';
        }
        else {
            playwrightTestPackagePath = require.resolve('@playwright/test/lib/test/cli', {
                paths: [__dirname, process.cwd()]
            });
        }
    }
    catch { }
    if (playwrightTestPackagePath) {
        require(playwrightTestPackagePath).addTestCommand(commander_1.default);
    }
    else {
        const command = commander_1.default.command('test');
        command.description('Run tests with Playwright Test. Available in @playwright/test package.');
        command.action(async (args, opts) => {
            console.error('Please install @playwright/test package to use Playwright Test.');
            console.error('  npm install -D @playwright/test');
            process.exit(1);
        });
    }
}
if (process.argv[2] === 'run-driver')
    driver_1.runDriver();
else if (process.argv[2] === 'run-server')
    driver_1.runServer(process.argv[3] ? +process.argv[3] : undefined);
else if (process.argv[2] === 'print-api-json')
    driver_1.printApiJson();
else if (process.argv[2] === 'launch-server')
    driver_1.launchBrowserServer(process.argv[3], process.argv[4]).catch(logErrorAndExit);
else
    commander_1.default.parse(process.argv);
async function launchContext(options, headless, executablePath) {
    validateOptions(options);
    const browserType = lookupBrowserType(options);
    const launchOptions = { headless, executablePath };
    if (options.channel)
        launchOptions.channel = options.channel;
    const contextOptions = 
    // Copy the device descriptor since we have to compare and modify the options.
    options.device ? { ...playwright.devices[options.device] } : {};
    // In headful mode, use host device scale factor for things to look nice.
    // In headless, keep things the way it works in Playwright by default.
    // Assume high-dpi on MacOS. TODO: this is not perfect.
    if (!headless)
        contextOptions.deviceScaleFactor = os_1.default.platform() === 'darwin' ? 2 : 1;
    // Work around the WebKit GTK scrolling issue.
    if (browserType.name() === 'webkit' && process.platform === 'linux') {
        delete contextOptions.hasTouch;
        delete contextOptions.isMobile;
    }
    if (contextOptions.isMobile && browserType.name() === 'firefox')
        contextOptions.isMobile = undefined;
    contextOptions.acceptDownloads = true;
    // Proxy
    if (options.proxyServer) {
        launchOptions.proxy = {
            server: options.proxyServer
        };
    }
    const browser = await browserType.launch(launchOptions);
    // Viewport size
    if (options.viewportSize) {
        try {
            const [width, height] = options.viewportSize.split(',').map(n => parseInt(n, 10));
            contextOptions.viewport = { width, height };
        }
        catch (e) {
            console.log('Invalid window size format: use "width, height", for example --window-size=800,600');
            process.exit(0);
        }
    }
    // Geolocation
    if (options.geolocation) {
        try {
            const [latitude, longitude] = options.geolocation.split(',').map(n => parseFloat(n.trim()));
            contextOptions.geolocation = {
                latitude,
                longitude
            };
        }
        catch (e) {
            console.log('Invalid geolocation format: user lat, long, for example --geolocation="37.819722,-122.478611"');
            process.exit(0);
        }
        contextOptions.permissions = ['geolocation'];
    }
    // User agent
    if (options.userAgent)
        contextOptions.userAgent = options.userAgent;
    // Lang
    if (options.lang)
        contextOptions.locale = options.lang;
    // Color scheme
    if (options.colorScheme)
        contextOptions.colorScheme = options.colorScheme;
    // Timezone
    if (options.timezone)
        contextOptions.timezoneId = options.timezone;
    // Storage
    if (options.loadStorage)
        contextOptions.storageState = options.loadStorage;
    // Close app when the last window closes.
    const context = await browser.newContext(contextOptions);
    let closingBrowser = false;
    async function closeBrowser() {
        // We can come here multiple times. For example, saving storage creates
        // a temporary page and we call closeBrowser again when that page closes.
        if (closingBrowser)
            return;
        closingBrowser = true;
        if (options.saveStorage)
            await context.storageState({ path: options.saveStorage }).catch(e => null);
        await browser.close();
    }
    context.on('page', page => {
        page.on('dialog', () => { }); // Prevent dialogs from being automatically dismissed.
        page.on('close', () => {
            const hasPage = browser.contexts().some(context => context.pages().length > 0);
            if (hasPage)
                return;
            // Avoid the error when the last page is closed because the browser has been closed.
            closeBrowser().catch(e => null);
        });
    });
    if (options.timeout) {
        context.setDefaultTimeout(parseInt(options.timeout, 10));
        context.setDefaultNavigationTimeout(parseInt(options.timeout, 10));
    }
    // Omit options that we add automatically for presentation purpose.
    delete launchOptions.headless;
    delete launchOptions.executablePath;
    delete contextOptions.deviceScaleFactor;
    delete contextOptions.acceptDownloads;
    return { browser, browserName: browserType.name(), context, contextOptions, launchOptions };
}
async function openPage(context, url) {
    const page = await context.newPage();
    if (url) {
        if (fs_1.default.existsSync(url))
            url = 'file://' + path_1.default.resolve(url);
        else if (!url.startsWith('http') && !url.startsWith('file://') && !url.startsWith('about:') && !url.startsWith('data:'))
            url = 'http://' + url;
        await page.goto(url);
    }
    return page;
}
async function open(options, url, language) {
    const { context, launchOptions, contextOptions } = await launchContext(options, !!process.env.PWTEST_CLI_HEADLESS, process.env.PWTEST_CLI_EXECUTABLE_PATH);
    await context._enableRecorder({
        language,
        launchOptions,
        contextOptions,
        device: options.device,
        saveStorage: options.saveStorage,
    });
    await openPage(context, url);
    if (process.env.PWTEST_CLI_EXIT)
        await Promise.all(context.pages().map(p => p.close()));
}
async function codegen(options, url, language, outputFile) {
    const { context, launchOptions, contextOptions } = await launchContext(options, !!process.env.PWTEST_CLI_HEADLESS, process.env.PWTEST_CLI_EXECUTABLE_PATH);
    await context._enableRecorder({
        language,
        launchOptions,
        contextOptions,
        device: options.device,
        saveStorage: options.saveStorage,
        startRecording: true,
        outputFile: outputFile ? path_1.default.resolve(outputFile) : undefined
    });
    await openPage(context, url);
    if (process.env.PWTEST_CLI_EXIT)
        await Promise.all(context.pages().map(p => p.close()));
}
async function waitForPage(page, captureOptions) {
    if (captureOptions.waitForSelector) {
        console.log(`Waiting for selector ${captureOptions.waitForSelector}...`);
        await page.waitForSelector(captureOptions.waitForSelector);
    }
    if (captureOptions.waitForTimeout) {
        console.log(`Waiting for timeout ${captureOptions.waitForTimeout}...`);
        await page.waitForTimeout(parseInt(captureOptions.waitForTimeout, 10));
    }
}
async function screenshot(options, captureOptions, url, path) {
    const { browser, context } = await launchContext(options, true);
    console.log('Navigating to ' + url);
    const page = await openPage(context, url);
    await waitForPage(page, captureOptions);
    console.log('Capturing screenshot into ' + path);
    await page.screenshot({ path, fullPage: !!captureOptions.fullPage });
    await browser.close();
}
async function pdf(options, captureOptions, url, path) {
    if (options.browser !== 'chromium') {
        console.error('PDF creation is only working with Chromium');
        process.exit(1);
    }
    const { browser, context } = await launchContext({ ...options, browser: 'chromium' }, true);
    console.log('Navigating to ' + url);
    const page = await openPage(context, url);
    await waitForPage(page, captureOptions);
    console.log('Saving as pdf into ' + path);
    await page.pdf({ path });
    await browser.close();
}
function lookupBrowserType(options) {
    let name = options.browser;
    if (options.device) {
        const device = playwright.devices[options.device];
        name = device.defaultBrowserType;
    }
    let browserType;
    switch (name) {
        case 'chromium':
            browserType = playwright.chromium;
            break;
        case 'webkit':
            browserType = playwright.webkit;
            break;
        case 'firefox':
            browserType = playwright.firefox;
            break;
        case 'cr':
            browserType = playwright.chromium;
            break;
        case 'wk':
            browserType = playwright.webkit;
            break;
        case 'ff':
            browserType = playwright.firefox;
            break;
    }
    if (browserType)
        return browserType;
    commander_1.default.help();
}
function validateOptions(options) {
    if (options.device && !(options.device in playwright.devices)) {
        console.log(`Device descriptor not found: '${options.device}', available devices are:`);
        for (const name in playwright.devices)
            console.log(`  "${name}"`);
        process.exit(0);
    }
    if (options.colorScheme && !['light', 'dark'].includes(options.colorScheme)) {
        console.log('Invalid color scheme, should be one of "light", "dark"');
        process.exit(0);
    }
}
function logErrorAndExit(e) {
    console.error(e);
    process.exit(1);
}
function language() {
    return process.env.PW_CLI_TARGET_LANG || 'test';
}
function commandWithOpenOptions(command, description, options) {
    let result = commander_1.default.command(command).description(description);
    for (const option of options)
        result = result.option(option[0], ...option.slice(1));
    return result
        .option('-b, --browser <browserType>', 'browser to use, one of cr, chromium, ff, firefox, wk, webkit', 'chromium')
        .option('--channel <channel>', 'Chromium distribution channel, "chrome", "chrome-beta", "msedge-dev", etc')
        .option('--color-scheme <scheme>', 'emulate preferred color scheme, "light" or "dark"')
        .option('--device <deviceName>', 'emulate device, for example  "iPhone 11"')
        .option('--geolocation <coordinates>', 'specify geolocation coordinates, for example "37.819722,-122.478611"')
        .option('--load-storage <filename>', 'load context storage state from the file, previously saved with --save-storage')
        .option('--lang <language>', 'specify language / locale, for example "en-GB"')
        .option('--proxy-server <proxy>', 'specify proxy server, for example "http://myproxy:3128" or "socks5://myproxy:8080"')
        .option('--save-storage <filename>', 'save context storage state at the end, for later use with --load-storage')
        .option('--timezone <time zone>', 'time zone to emulate, for example "Europe/Rome"')
        .option('--timeout <timeout>', 'timeout for Playwright actions in milliseconds', '10000')
        .option('--user-agent <ua string>', 'specify user agent string')
        .option('--viewport-size <size>', 'specify browser viewport size in pixels, for example "1280, 720"');
}
async function showTraceViewer(tracePath, browserName) {
    let stat;
    try {
        stat = fs_1.default.statSync(tracePath);
    }
    catch (e) {
        console.log(`No such file or directory: ${tracePath}`);
        return;
    }
    if (stat.isDirectory()) {
        const traceViewer = new traceViewer_1.TraceViewer(tracePath, browserName);
        await traceViewer.show();
        return;
    }
    const zipFile = tracePath;
    const dir = fs_1.default.mkdtempSync(path_1.default.join(os_1.default.tmpdir(), `playwright-trace`));
    process.on('exit', () => rimraf_1.default.sync(dir));
    try {
        await extract_zip_1.default(zipFile, { dir: dir });
    }
    catch (e) {
        console.log(`Invalid trace file: ${zipFile}`);
        return;
    }
    const traceViewer = new traceViewer_1.TraceViewer(dir, browserName);
    await traceViewer.show();
}
exports.showTraceViewer = showTraceViewer;
//# sourceMappingURL=cli.js.map