"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
Object.defineProperty(exports, "__esModule", { value: true });
exports.Connection = void 0;
const browser_1 = require("./browser");
const browserContext_1 = require("./browserContext");
const browserType_1 = require("./browserType");
const channelOwner_1 = require("./channelOwner");
const elementHandle_1 = require("./elementHandle");
const frame_1 = require("./frame");
const jsHandle_1 = require("./jsHandle");
const network_1 = require("./network");
const page_1 = require("./page");
const worker_1 = require("./worker");
const consoleMessage_1 = require("./consoleMessage");
const dialog_1 = require("./dialog");
const serializers_1 = require("../protocol/serializers");
const cdpSession_1 = require("./cdpSession");
const playwright_1 = require("./playwright");
const electron_1 = require("./electron");
const stream_1 = require("./stream");
const debugLogger_1 = require("../utils/debugLogger");
const selectors_1 = require("./selectors");
const utils_1 = require("../utils/utils");
const android_1 = require("./android");
const socksSocket_1 = require("./socksSocket");
const stackTrace_1 = require("../utils/stackTrace");
const artifact_1 = require("./artifact");
const events_1 = require("events");
class Root extends channelOwner_1.ChannelOwner {
    constructor(connection) {
        super(connection, '', '', {});
    }
}
class Connection extends events_1.EventEmitter {
    constructor(onClose) {
        super();
        this._objects = new Map();
        this._waitingForObject = new Map();
        this.onmessage = (message) => { };
        this._lastId = 0;
        this._callbacks = new Map();
        this._rootObject = new Root(this);
        this._onClose = onClose;
    }
    async waitForObjectWithKnownName(guid) {
        if (this._objects.has(guid))
            return this._objects.get(guid);
        return new Promise(f => this._waitingForObject.set(guid, f));
    }
    getObjectWithKnownName(guid) {
        return this._objects.get(guid);
    }
    async sendMessageToServer(guid, method, params, apiName) {
        const { stack, frames } = stackTrace_1.captureStackTrace();
        const id = ++this._lastId;
        const converted = { id, guid, method, params };
        // Do not include metadata in debug logs to avoid noise.
        debugLogger_1.debugLogger.log('channel:command', converted);
        this.onmessage({ ...converted, metadata: { stack: frames, apiName } });
        try {
            if (this._disconnectedErrorMessage)
                throw new Error(this._disconnectedErrorMessage);
            return await new Promise((resolve, reject) => this._callbacks.set(id, { resolve, reject }));
        }
        catch (e) {
            const innerStack = ((process.env.PWDEBUGIMPL || utils_1.isUnderTest()) && e.stack) ? e.stack.substring(e.stack.indexOf(e.message) + e.message.length) : '';
            e.stack = e.message + innerStack + '\n' + stack;
            throw e;
        }
    }
    _debugScopeState() {
        return this._rootObject._debugScopeState();
    }
    dispatch(message) {
        const { id, guid, method, params, result, error } = message;
        if (id) {
            debugLogger_1.debugLogger.log('channel:response', message);
            const callback = this._callbacks.get(id);
            if (!callback)
                throw new Error(`Cannot find command to respond: ${id}`);
            this._callbacks.delete(id);
            if (error)
                callback.reject(serializers_1.parseError(error));
            else
                callback.resolve(this._replaceGuidsWithChannels(result));
            return;
        }
        debugLogger_1.debugLogger.log('channel:event', message);
        if (method === '__create__') {
            this._createRemoteObject(guid, params.type, params.guid, params.initializer);
            return;
        }
        if (method === '__dispose__') {
            const object = this._objects.get(guid);
            if (!object)
                throw new Error(`Cannot find object to dispose: ${guid}`);
            object._dispose();
            return;
        }
        const object = this._objects.get(guid);
        if (!object)
            throw new Error(`Cannot find object to emit "${method}": ${guid}`);
        object._channel.emit(method, this._replaceGuidsWithChannels(params));
    }
    close() {
        if (this._onClose)
            this._onClose();
    }
    didDisconnect(errorMessage) {
        this._disconnectedErrorMessage = errorMessage;
        for (const callback of this._callbacks.values())
            callback.reject(new Error(errorMessage));
        this._callbacks.clear();
        this.emit('disconnect');
    }
    isDisconnected() {
        return !!this._disconnectedErrorMessage;
    }
    _replaceGuidsWithChannels(payload) {
        if (!payload)
            return payload;
        if (Array.isArray(payload))
            return payload.map(p => this._replaceGuidsWithChannels(p));
        if (payload.guid && this._objects.has(payload.guid))
            return this._objects.get(payload.guid)._channel;
        if (typeof payload === 'object') {
            const result = {};
            for (const key of Object.keys(payload))
                result[key] = this._replaceGuidsWithChannels(payload[key]);
            return result;
        }
        return payload;
    }
    _createRemoteObject(parentGuid, type, guid, initializer) {
        const parent = this._objects.get(parentGuid);
        if (!parent)
            throw new Error(`Cannot find parent object ${parentGuid} to create ${guid}`);
        let result;
        initializer = this._replaceGuidsWithChannels(initializer);
        switch (type) {
            case 'Android':
                result = new android_1.Android(parent, type, guid, initializer);
                break;
            case 'AndroidSocket':
                result = new android_1.AndroidSocket(parent, type, guid, initializer);
                break;
            case 'AndroidDevice':
                result = new android_1.AndroidDevice(parent, type, guid, initializer);
                break;
            case 'Artifact':
                result = new artifact_1.Artifact(parent, type, guid, initializer);
                break;
            case 'BindingCall':
                result = new page_1.BindingCall(parent, type, guid, initializer);
                break;
            case 'Browser':
                result = new browser_1.Browser(parent, type, guid, initializer);
                break;
            case 'BrowserContext':
                result = new browserContext_1.BrowserContext(parent, type, guid, initializer);
                break;
            case 'BrowserType':
                result = new browserType_1.BrowserType(parent, type, guid, initializer);
                break;
            case 'CDPSession':
                result = new cdpSession_1.CDPSession(parent, type, guid, initializer);
                break;
            case 'ConsoleMessage':
                result = new consoleMessage_1.ConsoleMessage(parent, type, guid, initializer);
                break;
            case 'Dialog':
                result = new dialog_1.Dialog(parent, type, guid, initializer);
                break;
            case 'Electron':
                result = new electron_1.Electron(parent, type, guid, initializer);
                break;
            case 'ElectronApplication':
                result = new electron_1.ElectronApplication(parent, type, guid, initializer);
                break;
            case 'ElementHandle':
                result = new elementHandle_1.ElementHandle(parent, type, guid, initializer);
                break;
            case 'Frame':
                result = new frame_1.Frame(parent, type, guid, initializer);
                break;
            case 'JSHandle':
                result = new jsHandle_1.JSHandle(parent, type, guid, initializer);
                break;
            case 'Page':
                result = new page_1.Page(parent, type, guid, initializer);
                break;
            case 'Playwright':
                result = new playwright_1.Playwright(parent, type, guid, initializer);
                break;
            case 'Request':
                result = new network_1.Request(parent, type, guid, initializer);
                break;
            case 'Response':
                result = new network_1.Response(parent, type, guid, initializer);
                break;
            case 'Route':
                result = new network_1.Route(parent, type, guid, initializer);
                break;
            case 'Stream':
                result = new stream_1.Stream(parent, type, guid, initializer);
                break;
            case 'Selectors':
                result = new selectors_1.SelectorsOwner(parent, type, guid, initializer);
                break;
            case 'WebSocket':
                result = new network_1.WebSocket(parent, type, guid, initializer);
                break;
            case 'Worker':
                result = new worker_1.Worker(parent, type, guid, initializer);
                break;
            case 'SocksSocket':
                result = new socksSocket_1.SocksSocket(parent, type, guid, initializer);
                break;
            default:
                throw new Error('Missing type ' + type);
        }
        const callback = this._waitingForObject.get(guid);
        if (callback) {
            callback(result);
            this._waitingForObject.delete(guid);
        }
        return result;
    }
}
exports.Connection = Connection;
//# sourceMappingURL=connection.js.map