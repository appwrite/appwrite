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
exports.AndroidDevice = exports.Android = void 0;
const debug_1 = __importDefault(require("debug"));
const events_1 = require("events");
const fs_1 = __importDefault(require("fs"));
const ws = __importStar(require("ws"));
const utils_1 = require("../../utils/utils");
const browserContext_1 = require("../browserContext");
const progress_1 = require("../progress");
const crBrowser_1 = require("../chromium/crBrowser");
const helper_1 = require("../helper");
const transport_1 = require("../../protocol/transport");
const debugLogger_1 = require("../../utils/debugLogger");
const timeoutSettings_1 = require("../../utils/timeoutSettings");
const instrumentation_1 = require("../instrumentation");
class Android extends instrumentation_1.SdkObject {
    constructor(backend, playwrightOptions) {
        super(playwrightOptions.rootSdkObject, 'android');
        this._devices = new Map();
        this._backend = backend;
        this._playwrightOptions = playwrightOptions;
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings();
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
    }
    async devices() {
        const devices = (await this._backend.devices()).filter(d => d.status === 'device');
        const newSerials = new Set();
        for (const d of devices) {
            newSerials.add(d.serial);
            if (this._devices.has(d.serial))
                continue;
            const device = await AndroidDevice.create(this, d);
            this._devices.set(d.serial, device);
        }
        for (const d of this._devices.keys()) {
            if (!newSerials.has(d))
                this._devices.delete(d);
        }
        return [...this._devices.values()];
    }
    _deviceClosed(device) {
        this._devices.delete(device.serial);
    }
}
exports.Android = Android;
class AndroidDevice extends instrumentation_1.SdkObject {
    constructor(android, backend, model) {
        super(android, 'android-device');
        this._lastId = 0;
        this._callbacks = new Map();
        this._webViews = new Map();
        this._browserConnections = new Set();
        this._isClosed = false;
        this._android = android;
        this._backend = backend;
        this.model = model;
        this.serial = backend.serial;
        this._timeoutSettings = new timeoutSettings_1.TimeoutSettings(android._timeoutSettings);
    }
    static async create(android, backend) {
        await backend.init();
        const model = await backend.runCommand('shell:getprop ro.product.model');
        const device = new AndroidDevice(android, backend, model.toString().trim());
        await device._init();
        return device;
    }
    async _init() {
        await this._refreshWebViews();
        const poll = () => {
            this._pollingWebViews = setTimeout(() => this._refreshWebViews().then(poll).catch(() => { }), 500);
        };
        poll();
    }
    setDefaultTimeout(timeout) {
        this._timeoutSettings.setDefaultTimeout(timeout);
    }
    async shell(command) {
        const result = await this._backend.runCommand(`shell:${command}`);
        await this._refreshWebViews();
        return result;
    }
    async open(command) {
        return await this._backend.open(`${command}`);
    }
    async screenshot() {
        return await this._backend.runCommand(`shell:screencap -p`);
    }
    async _driver() {
        if (!this._driverPromise)
            this._driverPromise = this._installDriver();
        return this._driverPromise;
    }
    async _installDriver() {
        debug_1.default('pw:android')('Stopping the old driver');
        await this.shell(`am force-stop com.microsoft.playwright.androiddriver`);
        debug_1.default('pw:android')('Uninstalling the old driver');
        await this.shell(`cmd package uninstall com.microsoft.playwright.androiddriver`);
        await this.shell(`cmd package uninstall com.microsoft.playwright.androiddriver.test`);
        debug_1.default('pw:android')('Installing the new driver');
        for (const file of ['android-driver.apk', 'android-driver-target.apk'])
            await this.installApk(await fs_1.default.promises.readFile(require.resolve(`../../../bin/${file}`)));
        debug_1.default('pw:android')('Starting the new driver');
        this.shell('am instrument -w com.microsoft.playwright.androiddriver.test/androidx.test.runner.AndroidJUnitRunner').catch(e => debug_1.default('pw:android')(e));
        const socket = await this._waitForLocalAbstract('playwright_android_driver_socket');
        const transport = new transport_1.Transport(socket, socket, socket, 'be');
        transport.onmessage = message => {
            const response = JSON.parse(message);
            const { id, result, error } = response;
            const callback = this._callbacks.get(id);
            if (!callback)
                return;
            if (error)
                callback.reject(new Error(error));
            else
                callback.fulfill(result);
            this._callbacks.delete(id);
        };
        return transport;
    }
    async _waitForLocalAbstract(socketName) {
        let socket;
        debug_1.default('pw:android')(`Polling the socket localabstract:${socketName}`);
        while (!socket) {
            try {
                socket = await this._backend.open(`localabstract:${socketName}`);
            }
            catch (e) {
                await new Promise(f => setTimeout(f, 250));
            }
        }
        debug_1.default('pw:android')(`Connected to localabstract:${socketName}`);
        return socket;
    }
    async send(method, params = {}) {
        // Patch the timeout in!
        params.timeout = this._timeoutSettings.timeout(params);
        const driver = await this._driver();
        const id = ++this._lastId;
        const result = new Promise((fulfill, reject) => this._callbacks.set(id, { fulfill, reject }));
        driver.send(JSON.stringify({ id, method, params }));
        return result;
    }
    async close() {
        this._isClosed = true;
        if (this._pollingWebViews)
            clearTimeout(this._pollingWebViews);
        for (const connection of this._browserConnections)
            await connection.close();
        if (this._driverPromise) {
            const driver = await this._driver();
            driver.close();
        }
        await this._backend.close();
        this._android._deviceClosed(this);
        this.emit(AndroidDevice.Events.Closed);
    }
    async launchBrowser(pkg = 'com.android.chrome', options) {
        debug_1.default('pw:android')('Force-stopping', pkg);
        await this._backend.runCommand(`shell:am force-stop ${pkg}`);
        const socketName = 'playwright-' + utils_1.createGuid();
        const commandLine = `_ --disable-fre --no-default-browser-check --no-first-run --remote-debugging-socket-name=${socketName}`;
        debug_1.default('pw:android')('Starting', pkg, commandLine);
        await this._backend.runCommand(`shell:echo "${commandLine}" > /data/local/tmp/chrome-command-line`);
        await this._backend.runCommand(`shell:am start -n ${pkg}/com.google.android.apps.chrome.Main about:blank`);
        return await this._connectToBrowser(socketName, options);
    }
    async connectToWebView(pid, sdkLanguage) {
        const webView = this._webViews.get(pid);
        if (!webView)
            throw new Error('WebView has been closed');
        return await this._connectToBrowser(`webview_devtools_remote_${pid}`, { sdkLanguage });
    }
    async _connectToBrowser(socketName, options) {
        const socket = await this._waitForLocalAbstract(socketName);
        const androidBrowser = new AndroidBrowser(this, socket);
        await androidBrowser._init();
        this._browserConnections.add(androidBrowser);
        const browserOptions = {
            ...this._android._playwrightOptions,
            name: 'clank',
            isChromium: true,
            slowMo: 0,
            persistent: { ...options, noDefaultViewport: true },
            artifactsDir: '',
            downloadsPath: '',
            tracesDir: '',
            browserProcess: new ClankBrowserProcess(androidBrowser),
            proxy: options.proxy,
            protocolLogger: helper_1.helper.debugProtocolLogger(),
            browserLogsCollector: new debugLogger_1.RecentLogsCollector()
        };
        browserContext_1.validateBrowserContextOptions(options, browserOptions);
        const browser = await crBrowser_1.CRBrowser.connect(androidBrowser, browserOptions);
        const controller = new progress_1.ProgressController(instrumentation_1.internalCallMetadata(), this);
        const defaultContext = browser._defaultContext;
        await controller.run(async (progress) => {
            await defaultContext._loadDefaultContextAsIs(progress);
        });
        {
            // TODO: remove after rolling to r838157
            // Force page scale factor update.
            const page = defaultContext.pages()[0];
            const crPage = page._delegate;
            await crPage._mainFrameSession._client.send('Emulation.setDeviceMetricsOverride', { mobile: false, width: 0, height: 0, deviceScaleFactor: 0 });
            await crPage._mainFrameSession._client.send('Emulation.clearDeviceMetricsOverride', {});
        }
        return defaultContext;
    }
    webViews() {
        return [...this._webViews.values()];
    }
    async installApk(content, options) {
        const args = options && options.args ? options.args : ['-r', '-t', '-S'];
        debug_1.default('pw:android')('Opening install socket');
        const installSocket = await this._backend.open(`shell:cmd package install ${args.join(' ')} ${content.length}`);
        debug_1.default('pw:android')('Writing driver bytes: ' + content.length);
        await installSocket.write(content);
        const success = await new Promise(f => installSocket.on('data', f));
        debug_1.default('pw:android')('Written driver bytes: ' + success);
        installSocket.close();
    }
    async push(content, path, mode = 0o644) {
        const socket = await this._backend.open(`sync:`);
        const sendHeader = async (command, length) => {
            const buffer = Buffer.alloc(command.length + 4);
            buffer.write(command, 0);
            buffer.writeUInt32LE(length, command.length);
            await socket.write(buffer);
        };
        const send = async (command, data) => {
            await sendHeader(command, data.length);
            await socket.write(data);
        };
        await send('SEND', Buffer.from(`${path},${mode}`));
        const maxChunk = 65535;
        for (let i = 0; i < content.length; i += maxChunk)
            await send('DATA', content.slice(i, i + maxChunk));
        await sendHeader('DONE', (Date.now() / 1000) | 0);
        const result = await new Promise(f => socket.once('data', f));
        const code = result.slice(0, 4).toString();
        if (code !== 'OKAY')
            throw new Error('Could not push: ' + code);
        socket.close();
    }
    async _refreshWebViews() {
        const sockets = (await this._backend.runCommand(`shell:cat /proc/net/unix | grep webview_devtools_remote`)).toString().split('\n');
        if (this._isClosed)
            return;
        const newPids = new Set();
        for (const line of sockets) {
            const match = line.match(/[^@]+@webview_devtools_remote_(\d+)/);
            if (!match)
                continue;
            const pid = +match[1];
            newPids.add(pid);
        }
        for (const pid of newPids) {
            if (this._webViews.has(pid))
                continue;
            const procs = (await this._backend.runCommand(`shell:ps -A | grep ${pid}`)).toString().split('\n');
            if (this._isClosed)
                return;
            let pkg = '';
            for (const proc of procs) {
                const match = proc.match(/[^\s]+\s+(\d+).*$/);
                if (!match)
                    continue;
                const p = match[1];
                if (+p !== pid)
                    continue;
                pkg = proc.substring(proc.lastIndexOf(' ') + 1);
            }
            const webView = { pid, pkg };
            this._webViews.set(pid, webView);
            this.emit(AndroidDevice.Events.WebViewAdded, webView);
        }
        for (const p of this._webViews.keys()) {
            if (!newPids.has(p)) {
                this._webViews.delete(p);
                this.emit(AndroidDevice.Events.WebViewRemoved, p);
            }
        }
    }
}
exports.AndroidDevice = AndroidDevice;
AndroidDevice.Events = {
    WebViewAdded: 'webViewAdded',
    WebViewRemoved: 'webViewRemoved',
    Closed: 'closed'
};
class AndroidBrowser extends events_1.EventEmitter {
    constructor(device, socket) {
        super();
        this._waitForNextTask = utils_1.makeWaitForNextTask();
        this.setMaxListeners(0);
        this.device = device;
        this._socket = socket;
        this._socket.on('close', () => {
            this._waitForNextTask(() => {
                if (this.onclose)
                    this.onclose();
            });
        });
        this._receiver = new ws.Receiver();
        this._receiver.on('message', message => {
            this._waitForNextTask(() => {
                if (this.onmessage)
                    this.onmessage(JSON.parse(message));
            });
        });
    }
    async _init() {
        await this._socket.write(Buffer.from(`GET /devtools/browser HTTP/1.1\r
Upgrade: WebSocket\r
Connection: Upgrade\r
Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r
Sec-WebSocket-Version: 13\r
\r
`));
        // HTTP Upgrade response.
        await new Promise(f => this._socket.once('data', f));
        // Start sending web frame to receiver.
        this._socket.on('data', data => this._receiver._write(data, 'binary', () => { }));
    }
    async send(s) {
        await this._socket.write(encodeWebFrame(JSON.stringify(s)));
    }
    async close() {
        this._socket.close();
    }
}
function encodeWebFrame(data) {
    return ws.Sender.frame(Buffer.from(data), {
        opcode: 1,
        mask: true,
        fin: true,
        readOnly: true
    })[0];
}
class ClankBrowserProcess {
    constructor(browser) {
        this._browser = browser;
    }
    async kill() {
    }
    async close() {
        await this._browser.close();
    }
}
//# sourceMappingURL=android.js.map