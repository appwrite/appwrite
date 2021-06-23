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
exports.ChannelOwner = void 0;
const events_1 = require("events");
const validator_1 = require("../protocol/validator");
const debugLogger_1 = require("../utils/debugLogger");
const stackTrace_1 = require("../utils/stackTrace");
class ChannelOwner extends events_1.EventEmitter {
    constructor(parent, type, guid, initializer) {
        super();
        this._objects = new Map();
        this.setMaxListeners(0);
        this._connection = parent instanceof ChannelOwner ? parent._connection : parent;
        this._type = type;
        this._guid = guid;
        this._parent = parent instanceof ChannelOwner ? parent : undefined;
        this._connection._objects.set(guid, this);
        if (this._parent) {
            this._parent._objects.set(guid, this);
            this._logger = this._parent._logger;
        }
        this._channel = this._createChannel(new events_1.EventEmitter(), '');
        this._initializer = initializer;
    }
    _dispose() {
        // Clean up from parent and connection.
        if (this._parent)
            this._parent._objects.delete(this._guid);
        this._connection._objects.delete(this._guid);
        // Dispose all children.
        for (const object of [...this._objects.values()])
            object._dispose();
        this._objects.clear();
    }
    _debugScopeState() {
        return {
            _guid: this._guid,
            objects: Array.from(this._objects.values()).map(o => o._debugScopeState()),
        };
    }
    _createChannel(base, apiName) {
        const channel = new Proxy(base, {
            get: (obj, prop) => {
                if (prop === 'debugScopeState')
                    return (params) => this._connection.sendMessageToServer(this._guid, prop, params, apiName);
                if (typeof prop === 'string') {
                    const validator = scheme[paramsName(this._type, prop)];
                    if (validator)
                        return (params) => this._connection.sendMessageToServer(this._guid, prop, validator(params, ''), apiName);
                }
                return obj[prop];
            },
        });
        channel._object = this;
        return channel;
    }
    async _wrapApiCall(apiName, func, logger) {
        logger = logger || this._logger;
        try {
            logApiCall(logger, `=> ${apiName} started`);
            const channel = this._createChannel({}, apiName);
            const result = await func(channel);
            logApiCall(logger, `<= ${apiName} succeeded`);
            return result;
        }
        catch (e) {
            logApiCall(logger, `<= ${apiName} failed`);
            stackTrace_1.rewriteErrorMessage(e, `${apiName}: ` + e.message);
            throw e;
        }
    }
    _waitForEventInfoBefore(waitId, apiName) {
        this._connection.sendMessageToServer(this._guid, 'waitForEventInfo', { info: { apiName, waitId, phase: 'before' } }, undefined).catch(() => { });
    }
    _waitForEventInfoAfter(waitId, error) {
        this._connection.sendMessageToServer(this._guid, 'waitForEventInfo', { info: { waitId, phase: 'after', error } }, undefined).catch(() => { });
    }
    _waitForEventInfoLog(waitId, message) {
        this._connection.sendMessageToServer(this._guid, 'waitForEventInfo', { info: { waitId, phase: 'log', message } }, undefined).catch(() => { });
    }
    toJSON() {
        // Jest's expect library tries to print objects sometimes.
        // RPC objects can contain links to lots of other objects,
        // which can cause jest to crash. Let's help it out
        // by just returning the important values.
        return {
            _type: this._type,
            _guid: this._guid,
        };
    }
}
exports.ChannelOwner = ChannelOwner;
function logApiCall(logger, message) {
    if (logger && logger.isEnabled('api', 'info'))
        logger.log('api', 'info', message, [], { color: 'cyan' });
    debugLogger_1.debugLogger.log('api', message);
}
function paramsName(type, method) {
    return type + method[0].toUpperCase() + method.substring(1) + 'Params';
}
const tChannel = (name) => {
    return (arg, path) => {
        if (arg._object instanceof ChannelOwner && (name === '*' || arg._object._type === name))
            return { guid: arg._object._guid };
        throw new validator_1.ValidationError(`${path}: expected ${name}`);
    };
};
const scheme = validator_1.createScheme(tChannel);
//# sourceMappingURL=channelOwner.js.map