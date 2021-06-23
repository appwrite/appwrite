"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an 'AS IS' BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.FFSession = exports.FFSessionEvents = exports.FFConnection = exports.kBrowserCloseMessageId = exports.ConnectionEvents = void 0;
const events_1 = require("events");
const utils_1 = require("../../utils/utils");
const stackTrace_1 = require("../../utils/stackTrace");
const debugLogger_1 = require("../../utils/debugLogger");
const helper_1 = require("../helper");
exports.ConnectionEvents = {
    Disconnected: Symbol('Disconnected'),
};
// FFPlaywright uses this special id to issue Browser.close command which we
// should ignore.
exports.kBrowserCloseMessageId = -9999;
class FFConnection extends events_1.EventEmitter {
    constructor(transport, protocolLogger, browserLogsCollector) {
        super();
        this.setMaxListeners(0);
        this._transport = transport;
        this._protocolLogger = protocolLogger;
        this._browserLogsCollector = browserLogsCollector;
        this._lastId = 0;
        this._callbacks = new Map();
        this._transport.onmessage = this._onMessage.bind(this);
        this._transport.onclose = this._onClose.bind(this);
        this._sessions = new Map();
        this._closed = false;
        this.on = super.on;
        this.addListener = super.addListener;
        this.off = super.removeListener;
        this.removeListener = super.removeListener;
        this.once = super.once;
    }
    async send(method, params) {
        this._checkClosed(method);
        const id = this.nextMessageId();
        this._rawSend({ id, method, params });
        return new Promise((resolve, reject) => {
            this._callbacks.set(id, { resolve, reject, error: new Error(), method });
        });
    }
    nextMessageId() {
        return ++this._lastId;
    }
    _checkClosed(method) {
        if (this._closed)
            throw new Error(`Protocol error (${method}): Browser closed.` + helper_1.helper.formatBrowserLogs(this._browserLogsCollector.recentLogs()));
    }
    _rawSend(message) {
        this._protocolLogger('send', message);
        this._transport.send(message);
    }
    async _onMessage(message) {
        this._protocolLogger('receive', message);
        if (message.id === exports.kBrowserCloseMessageId)
            return;
        if (message.sessionId) {
            const session = this._sessions.get(message.sessionId);
            if (session)
                session.dispatchMessage(message);
        }
        else if (message.id) {
            const callback = this._callbacks.get(message.id);
            // Callbacks could be all rejected if someone has called `.dispose()`.
            if (callback) {
                this._callbacks.delete(message.id);
                if (message.error)
                    callback.reject(createProtocolError(callback.error, callback.method, message.error));
                else
                    callback.resolve(message.result);
            }
        }
        else {
            Promise.resolve().then(() => this.emit(message.method, message.params));
        }
    }
    _onClose() {
        this._closed = true;
        this._transport.onmessage = undefined;
        this._transport.onclose = undefined;
        const formattedBrowserLogs = helper_1.helper.formatBrowserLogs(this._browserLogsCollector.recentLogs());
        for (const session of this._sessions.values())
            session.dispose(formattedBrowserLogs);
        this._sessions.clear();
        for (const callback of this._callbacks.values())
            callback.reject(stackTrace_1.rewriteErrorMessage(callback.error, `Protocol error (${callback.method}): Browser closed.` + formattedBrowserLogs));
        this._callbacks.clear();
        Promise.resolve().then(() => this.emit(exports.ConnectionEvents.Disconnected));
    }
    close() {
        if (!this._closed)
            this._transport.close();
    }
    createSession(sessionId, type) {
        const session = new FFSession(this, type, sessionId, message => this._rawSend({ ...message, sessionId }));
        this._sessions.set(sessionId, session);
        return session;
    }
}
exports.FFConnection = FFConnection;
exports.FFSessionEvents = {
    Disconnected: Symbol('Disconnected')
};
class FFSession extends events_1.EventEmitter {
    constructor(connection, targetType, sessionId, rawSend) {
        super();
        this._disposed = false;
        this._crashed = false;
        this.setMaxListeners(0);
        this._callbacks = new Map();
        this._connection = connection;
        this._targetType = targetType;
        this._sessionId = sessionId;
        this._rawSend = rawSend;
        this.on = super.on;
        this.addListener = super.addListener;
        this.off = super.removeListener;
        this.removeListener = super.removeListener;
        this.once = super.once;
    }
    markAsCrashed() {
        this._crashed = true;
    }
    async send(method, params) {
        if (this._crashed)
            throw new Error('Page crashed');
        this._connection._checkClosed(method);
        if (this._disposed)
            throw new Error(`Protocol error (${method}): Session closed. Most likely the ${this._targetType} has been closed.`);
        const id = this._connection.nextMessageId();
        this._rawSend({ method, params, id });
        return new Promise((resolve, reject) => {
            this._callbacks.set(id, { resolve, reject, error: new Error(), method });
        });
    }
    sendMayFail(method, params) {
        return this.send(method, params).catch(error => debugLogger_1.debugLogger.log('error', error));
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
        else {
            utils_1.assert(!object.id);
            Promise.resolve().then(() => this.emit(object.method, object.params));
        }
    }
    dispose(formattedBrowserLogs) {
        for (const callback of this._callbacks.values())
            callback.reject(stackTrace_1.rewriteErrorMessage(callback.error, `Protocol error (${callback.method}): Target closed.` + formattedBrowserLogs));
        this._callbacks.clear();
        this._disposed = true;
        this._connection._sessions.delete(this._sessionId);
        Promise.resolve().then(() => this.emit(exports.FFSessionEvents.Disconnected));
    }
}
exports.FFSession = FFSession;
function createProtocolError(error, method, protocolError) {
    let message = `Protocol error (${method}): ${protocolError.message}`;
    if ('data' in protocolError)
        message += ` ${protocolError.data}`;
    return stackTrace_1.rewriteErrorMessage(error, message);
}
//# sourceMappingURL=ffConnection.js.map