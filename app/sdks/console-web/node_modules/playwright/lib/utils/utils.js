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
exports.getUserAgent = exports.isLocalIpAddress = exports.canAccessFile = exports.removeFolders = exports.createGuid = exports.calculateSha1 = exports.monotonicTime = exports.headersArrayToObject = exports.headersObjectToArray = exports.mkdirIfNeeded = exports.getAsBooleanFromENV = exports.getFromENV = exports.isUnderTest = exports.setUnderTest = exports.debugMode = exports.isError = exports.isObject = exports.isRegExp = exports.isString = exports.debugAssert = exports.assert = exports.makeWaitForNextTask = exports.spawnAsync = exports.downloadFile = exports.fetchData = exports.existsAsync = void 0;
const path_1 = __importDefault(require("path"));
const fs_1 = __importDefault(require("fs"));
const rimraf_1 = __importDefault(require("rimraf"));
const crypto = __importStar(require("crypto"));
const os_1 = __importDefault(require("os"));
const child_process_1 = require("child_process");
const proxy_from_env_1 = require("proxy-from-env");
const URL = __importStar(require("url"));
// `https-proxy-agent` v5 is written in TypeScript and exposes generated types.
// However, as of June 2020, its types are generated with tsconfig that enables
// `esModuleInterop` option.
//
// As a result, we can't depend on the package unless we enable the option
// for our codebase. Instead of doing this, we abuse "require" to import module
// without types.
const ProxyAgent = require('https-proxy-agent');
const existsAsync = (path) => new Promise(resolve => fs_1.default.stat(path, err => resolve(!err)));
exports.existsAsync = existsAsync;
function httpRequest(url, method, response) {
    let options = URL.parse(url);
    options.method = method;
    const proxyURL = proxy_from_env_1.getProxyForUrl(url);
    if (proxyURL) {
        if (url.startsWith('http:')) {
            const proxy = URL.parse(proxyURL);
            options = {
                path: options.href,
                host: proxy.hostname,
                port: proxy.port,
            };
        }
        else {
            const parsedProxyURL = URL.parse(proxyURL);
            parsedProxyURL.secureProxy = parsedProxyURL.protocol === 'https:';
            options.agent = new ProxyAgent(parsedProxyURL);
            options.rejectUnauthorized = false;
        }
    }
    const requestCallback = (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location)
            httpRequest(res.headers.location, method, response);
        else
            response(res);
    };
    const request = options.protocol === 'https:' ?
        require('https').request(options, requestCallback) :
        require('http').request(options, requestCallback);
    request.end();
    return request;
}
function fetchData(url) {
    return new Promise((resolve, reject) => {
        httpRequest(url, 'GET', function (response) {
            if (response.statusCode !== 200) {
                reject(new Error(`fetch failed: server returned code ${response.statusCode}. URL: ${url}`));
                return;
            }
            let body = '';
            response.on('data', (chunk) => body += chunk);
            response.on('error', (error) => reject(error));
            response.on('end', () => resolve(body));
        }).on('error', (error) => reject(error));
    });
}
exports.fetchData = fetchData;
function downloadFile(url, destinationPath, options = {}) {
    const { progressCallback, log = () => { }, } = options;
    log(`running download:`);
    log(`-- from url: ${url}`);
    log(`-- to location: ${destinationPath}`);
    let fulfill = ({ error }) => { };
    let downloadedBytes = 0;
    let totalBytes = 0;
    const promise = new Promise(x => { fulfill = x; });
    const request = httpRequest(url, 'GET', response => {
        log(`-- response status code: ${response.statusCode}`);
        if (response.statusCode !== 200) {
            const error = new Error(`Download failed: server returned code ${response.statusCode}. URL: ${url}`);
            // consume response data to free up memory
            response.resume();
            fulfill({ error });
            return;
        }
        const file = fs_1.default.createWriteStream(destinationPath);
        file.on('finish', () => fulfill({ error: null }));
        file.on('error', error => fulfill({ error }));
        response.pipe(file);
        totalBytes = parseInt(response.headers['content-length'], 10);
        log(`-- total bytes: ${totalBytes}`);
        if (progressCallback)
            response.on('data', onData);
    });
    request.on('error', (error) => fulfill({ error }));
    return promise;
    function onData(chunk) {
        downloadedBytes += chunk.length;
        progressCallback(downloadedBytes, totalBytes);
    }
}
exports.downloadFile = downloadFile;
function spawnAsync(cmd, args, options) {
    const process = child_process_1.spawn(cmd, args, options);
    return new Promise(resolve => {
        let stdout = '';
        let stderr = '';
        if (process.stdout)
            process.stdout.on('data', data => stdout += data);
        if (process.stderr)
            process.stderr.on('data', data => stderr += data);
        process.on('close', code => resolve({ stdout, stderr, code }));
        process.on('error', error => resolve({ stdout, stderr, code: 0, error }));
    });
}
exports.spawnAsync = spawnAsync;
// See https://joel.tools/microtasks/
function makeWaitForNextTask() {
    // As of Mar 2021, Electorn v12 doesn't create new task with `setImmediate` despite
    // using Node 14 internally, so we fallback to `setTimeout(0)` instead.
    // @see https://github.com/electron/electron/issues/28261
    if (process.versions.electron)
        return (callback) => setTimeout(callback, 0);
    if (parseInt(process.versions.node, 10) >= 11)
        return setImmediate;
    // Unlike Node 11, Node 10 and less have a bug with Task and MicroTask execution order:
    // - https://github.com/nodejs/node/issues/22257
    //
    // So we can't simply run setImmediate to dispatch code in a following task.
    // However, we can run setImmediate from-inside setImmediate to make sure we're getting
    // in the following task.
    let spinning = false;
    const callbacks = [];
    const loop = () => {
        const callback = callbacks.shift();
        if (!callback) {
            spinning = false;
            return;
        }
        setImmediate(loop);
        // Make sure to call callback() as the last thing since it's
        // untrusted code that might throw.
        callback();
    };
    return (callback) => {
        callbacks.push(callback);
        if (!spinning) {
            spinning = true;
            setImmediate(loop);
        }
    };
}
exports.makeWaitForNextTask = makeWaitForNextTask;
function assert(value, message) {
    if (!value)
        throw new Error(message);
}
exports.assert = assert;
function debugAssert(value, message) {
    if (isUnderTest() && !value)
        throw new Error(message);
}
exports.debugAssert = debugAssert;
function isString(obj) {
    return typeof obj === 'string' || obj instanceof String;
}
exports.isString = isString;
function isRegExp(obj) {
    return obj instanceof RegExp || Object.prototype.toString.call(obj) === '[object RegExp]';
}
exports.isRegExp = isRegExp;
function isObject(obj) {
    return typeof obj === 'object' && obj !== null;
}
exports.isObject = isObject;
function isError(obj) {
    return obj instanceof Error || (obj && obj.__proto__ && obj.__proto__.name === 'Error');
}
exports.isError = isError;
const debugEnv = getFromENV('PWDEBUG') || '';
function debugMode() {
    if (debugEnv === 'console')
        return 'console';
    return debugEnv ? 'inspector' : '';
}
exports.debugMode = debugMode;
let _isUnderTest = false;
function setUnderTest() {
    _isUnderTest = true;
}
exports.setUnderTest = setUnderTest;
function isUnderTest() {
    return _isUnderTest;
}
exports.isUnderTest = isUnderTest;
function getFromENV(name) {
    let value = process.env[name];
    value = value === undefined ? process.env[`npm_config_${name.toLowerCase()}`] : value;
    value = value === undefined ? process.env[`npm_package_config_${name.toLowerCase()}`] : value;
    return value;
}
exports.getFromENV = getFromENV;
function getAsBooleanFromENV(name) {
    const value = getFromENV(name);
    return !!value && value !== 'false' && value !== '0';
}
exports.getAsBooleanFromENV = getAsBooleanFromENV;
async function mkdirIfNeeded(filePath) {
    // This will harmlessly throw on windows if the dirname is the root directory.
    await fs_1.default.promises.mkdir(path_1.default.dirname(filePath), { recursive: true }).catch(() => { });
}
exports.mkdirIfNeeded = mkdirIfNeeded;
function headersObjectToArray(headers) {
    const result = [];
    for (const name in headers) {
        if (!Object.is(headers[name], undefined))
            result.push({ name, value: headers[name] });
    }
    return result;
}
exports.headersObjectToArray = headersObjectToArray;
function headersArrayToObject(headers, lowerCase) {
    const result = {};
    for (const { name, value } of headers)
        result[lowerCase ? name.toLowerCase() : name] = value;
    return result;
}
exports.headersArrayToObject = headersArrayToObject;
function monotonicTime() {
    const [seconds, nanoseconds] = process.hrtime();
    return seconds * 1000 + (nanoseconds / 1000 | 0) / 1000;
}
exports.monotonicTime = monotonicTime;
function calculateSha1(buffer) {
    const hash = crypto.createHash('sha1');
    hash.update(buffer);
    return hash.digest('hex');
}
exports.calculateSha1 = calculateSha1;
function createGuid() {
    return crypto.randomBytes(16).toString('hex');
}
exports.createGuid = createGuid;
async function removeFolders(dirs) {
    return await Promise.all(dirs.map((dir) => {
        return new Promise(fulfill => {
            rimraf_1.default(dir, { maxBusyTries: 10 }, error => {
                fulfill(error);
            });
        });
    }));
}
exports.removeFolders = removeFolders;
function canAccessFile(file) {
    if (!file)
        return false;
    try {
        fs_1.default.accessSync(file);
        return true;
    }
    catch (e) {
        return false;
    }
}
exports.canAccessFile = canAccessFile;
const localIpAddresses = [
    'localhost',
    '127.0.0.1',
    '::ffff:127.0.0.1',
    '::1',
    '0000:0000:0000:0000:0000:0000:0000:0001', // WebKit (Windows)
];
function isLocalIpAddress(ipAdress) {
    return localIpAddresses.includes(ipAdress);
}
exports.isLocalIpAddress = isLocalIpAddress;
function getUserAgent() {
    const packageJson = require('./../../package.json');
    return `Playwright/${packageJson.version} (${os_1.default.arch()}/${os_1.default.platform()}/${os_1.default.release()})`;
}
exports.getUserAgent = getUserAgent;
//# sourceMappingURL=utils.js.map