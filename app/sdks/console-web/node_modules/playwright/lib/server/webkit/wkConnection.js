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
Object.defineProperty(exports, "__esModule", { value: true });
exports.createProtocolError = exports.WKSession = exports.WKConnection = exports.kPageProxyMessageReceived = exports.kBrowserCloseMessageId = void 0;
const events_1 = require("events");
const utils_1 = require("../../utils/utils");
const stackTrace_1 = require("../../utils/stackTrace");
const debugLogger_1 = require("../../utils/debugLogger");
const helper_1 = require("../helper");
const errors_1 = require("../../utils/errors");
// WKPlaywright uses this special id to issue Browser.close command which we
// should ignore.
exports.kBrowserCloseMessageId = -9999;
// We emulate kPageProxyMessageReceived message to unify it with Browser.pageProxyCreated
// and Browser.pageProxyDestroyed for easier management.
exports.kPageProxyMessageReceived = 'kPageProxyMessageReceived';
class WKConnection {
    constructor(transport, onDisconnect, protocolLogger, browserLogsCollector) {
        this._lastId = 0;
        this._closed = false;
        this._transport = transport;
        this._transport.onmessage = this._dispatchMessage.bind(this);
        this._transport.onclose = this._onClose.bind(this);
        this._onDisconnect = onDisconnect;
        this._protocolLogger = protocolLogger;
        this._browserLogsCollector = browserLogsCollector;
        this.browserSession = new WKSession(this, '', errors_1.kBrowserClosedError, (message) => {
            this.rawSend(message);
        });
    }
    nextMessageId() {
        return ++this._lastId;
    }
    rawSend(message) {
        this._protocolLogger('send', message);
        this._transport.send(message);
    }
    _dispatchMessage(message) {
        this._protocolLogger('receive', message);
        if (message.id === exports.kBrowserCloseMessageId)
            return;
        if (message.pageProxyId) {
            const payload = { message: message, pageProxyId: message.pageProxyId };
            this.browserSession.dispatchMessage({ method: exports.kPageProxyMessageReceived, params: payload });
            return;
        }
        this.browserSession.dispatchMessage(message);
    }
    _onClose() {
        this._closed = true;
        this._transport.onmessage = undefined;
        this._transport.onclose = undefined;
        this.browserSession.dispose(true);
        this._onDisconnect();
    }
    isClosed() {
        return this._closed;
    }
    close() {
        if (!this._closed)
            this._transport.close();
    }
}
exports.WKConnection = WKConnection;
class WKSession extends events_1.EventEmitter {
    constructor(connection, sessionId, errorText, rawSend) {
        super();
        this._disposed = false;
        this._callbacks = new Map();
        this._crashed = false;
        this.setMaxListeners(0);
        this.connection = connection;
        this.sessionId = sessionId;
        this._rawSend = rawSend;
        this.errorText = errorText;
        this.on = super.on;
        this.off = super.removeListener;
        this.addListener = super.addListener;
        this.removeListener = super.removeListener;
        this.once = super.once;
    }
    async send(method, params) {
        if (this._crashed)
            throw new Error('Target crashed');
        if (this._disposed)
            throw new Error(`Protocol error (${method}): ${this.errorText}`);
        const id = this.connection.nextMessageId();
        const messageObj = { id, method, params };
        this._rawSend(messageObj);
        return new Promise((resolve, reject) => {
            this._callbacks.set(id, { resolve, reject, error: new Error(), method });
        });
    }
    sendMayFail(method, params) {
        return this.send(method, params).catch(error => debugLogger_1.debugLogger.log('error', error));
    }
    markAsCrashed() {
        this._crashed = true;
    }
    isDisposed() {
        return this._disposed;
    }
    dispose(disconnected) {
        if (disconnected)
            this.errorText = 'Browser closed.' + helper_1.helper.formatBrowserLogs(this.connection._browserLogsCollector.recentLogs());
        for (const callback of this._callbacks.values())
            callback.reject(stackTrace_1.rewriteErrorMessage(callback.error, `Protocol error (${callback.method}): ${this.errorText}`));
        this._callbacks.clear();
        this._disposed = true;
    }
    dispatchMessage(object) {
        if (object.id && this._callbacks.has(object.id)) {
            const callback = this._callbacks.get(object.id);
            this._callbacks.delete(object.id);
            if (object.error)
                callback.reject(createProtocolError(callback.error, callback.method, object.error));
            else
                callback.resolve(object.result);
        }
        else if (object.id && !object.error) {
            // Response might come after session has been disposed and rejected all callbacks.
            utils_1.assert(this.isDisposed());
        }
        else {
            Promise.resolve().then(() => this.emit(object.method, object.params));
        }
    }
}
exports.WKSession = WKSession;
function createProtocolError(error, method, protocolError) {
    let message = `Protocol error (${method}): ${protocolError.message}`;
    if ('data' in protocolError)
        message += ` ${JSON.stringify(protocolError.data)}`;
    return stackTrace_1.rewriteErrorMessage(error, message);
}
exports.createProtocolError = createProtocolError;
//# sourceMappingURL=wkConnection.js.map