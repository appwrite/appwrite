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
exports.validateHeaders = exports.WebSocket = exports.Response = exports.Route = exports.Request = void 0;
const url_1 = require("url");
const channelOwner_1 = require("./channelOwner");
const frame_1 = require("./frame");
const fs_1 = __importDefault(require("fs"));
const mime = __importStar(require("mime"));
const utils_1 = require("../utils/utils");
const events_1 = require("./events");
const waiter_1 = require("./waiter");
class Request extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._redirectedFrom = null;
        this._redirectedTo = null;
        this._failureText = null;
        this._redirectedFrom = Request.fromNullable(initializer.redirectedFrom);
        if (this._redirectedFrom)
            this._redirectedFrom._redirectedTo = this;
        this._headers = utils_1.headersArrayToObject(initializer.headers, true /* lowerCase */);
        this._postData = initializer.postData ? Buffer.from(initializer.postData, 'base64') : null;
        this._timing = {
            startTime: 0,
            domainLookupStart: -1,
            domainLookupEnd: -1,
            connectStart: -1,
            secureConnectionStart: -1,
            connectEnd: -1,
            requestStart: -1,
            responseStart: -1,
            responseEnd: -1,
        };
    }
    static from(request) {
        return request._object;
    }
    static fromNullable(request) {
        return request ? Request.from(request) : null;
    }
    url() {
        return this._initializer.url;
    }
    resourceType() {
        return this._initializer.resourceType;
    }
    method() {
        return this._initializer.method;
    }
    postData() {
        return this._postData ? this._postData.toString('utf8') : null;
    }
    postDataBuffer() {
        return this._postData;
    }
    postDataJSON() {
        const postData = this.postData();
        if (!postData)
            return null;
        const contentType = this.headers()['content-type'];
        if (contentType === 'application/x-www-form-urlencoded') {
            const entries = {};
            const parsed = new url_1.URLSearchParams(postData);
            for (const [k, v] of parsed.entries())
                entries[k] = v;
            return entries;
        }
        try {
            return JSON.parse(postData);
        }
        catch (e) {
            throw new Error('POST data is not a valid JSON object: ' + postData);
        }
    }
    headers() {
        return { ...this._headers };
    }
    async response() {
        return this._wrapApiCall('request.response', async (channel) => {
            return Response.fromNullable((await channel.response()).response);
        });
    }
    frame() {
        return frame_1.Frame.from(this._initializer.frame);
    }
    isNavigationRequest() {
        return this._initializer.isNavigationRequest;
    }
    redirectedFrom() {
        return this._redirectedFrom;
    }
    redirectedTo() {
        return this._redirectedTo;
    }
    failure() {
        if (this._failureText === null)
            return null;
        return {
            errorText: this._failureText
        };
    }
    timing() {
        return this._timing;
    }
    _finalRequest() {
        return this._redirectedTo ? this._redirectedTo._finalRequest() : this;
    }
}
exports.Request = Request;
class Route extends channelOwner_1.ChannelOwner {
    static from(route) {
        return route._object;
    }
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
    }
    request() {
        return Request.from(this._initializer.request);
    }
    async abort(errorCode) {
        return this._wrapApiCall('route.abort', async (channel) => {
            await channel.abort({ errorCode });
        });
    }
    async fulfill(options = {}) {
        return this._wrapApiCall('route.fulfill', async (channel) => {
            let body = '';
            let isBase64 = false;
            let length = 0;
            if (options.path) {
                const buffer = await fs_1.default.promises.readFile(options.path);
                body = buffer.toString('base64');
                isBase64 = true;
                length = buffer.length;
            }
            else if (utils_1.isString(options.body)) {
                body = options.body;
                isBase64 = false;
                length = Buffer.byteLength(body);
            }
            else if (options.body) {
                body = options.body.toString('base64');
                isBase64 = true;
                length = options.body.length;
            }
            const headers = {};
            for (const header of Object.keys(options.headers || {}))
                headers[header.toLowerCase()] = String(options.headers[header]);
            if (options.contentType)
                headers['content-type'] = String(options.contentType);
            else if (options.path)
                headers['content-type'] = mime.getType(options.path) || 'application/octet-stream';
            if (length && !('content-length' in headers))
                headers['content-length'] = String(length);
            await channel.fulfill({
                status: options.status || 200,
                headers: utils_1.headersObjectToArray(headers),
                body,
                isBase64
            });
        });
    }
    async continue(options = {}) {
        return this._wrapApiCall('route.continue', async (channel) => {
            const postDataBuffer = utils_1.isString(options.postData) ? Buffer.from(options.postData, 'utf8') : options.postData;
            await channel.continue({
                url: options.url,
                method: options.method,
                headers: options.headers ? utils_1.headersObjectToArray(options.headers) : undefined,
                postData: postDataBuffer ? postDataBuffer.toString('base64') : undefined,
            });
        });
    }
}
exports.Route = Route;
class Response extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._headers = utils_1.headersArrayToObject(initializer.headers, true /* lowerCase */);
        this._request = Request.from(this._initializer.request);
        this._request._headers = utils_1.headersArrayToObject(initializer.requestHeaders, true /* lowerCase */);
        Object.assign(this._request._timing, this._initializer.timing);
    }
    static from(response) {
        return response._object;
    }
    static fromNullable(response) {
        return response ? Response.from(response) : null;
    }
    url() {
        return this._initializer.url;
    }
    ok() {
        return this._initializer.status === 0 || (this._initializer.status >= 200 && this._initializer.status <= 299);
    }
    status() {
        return this._initializer.status;
    }
    statusText() {
        return this._initializer.statusText;
    }
    headers() {
        return { ...this._headers };
    }
    async finished() {
        const result = await this._channel.finished();
        if (result.error)
            return new Error(result.error);
        return null;
    }
    async body() {
        return this._wrapApiCall('response.body', async (channel) => {
            return Buffer.from((await channel.body()).binary, 'base64');
        });
    }
    async text() {
        const content = await this.body();
        return content.toString('utf8');
    }
    async json() {
        const content = await this.text();
        return JSON.parse(content);
    }
    request() {
        return this._request;
    }
    frame() {
        return this._request.frame();
    }
}
exports.Response = Response;
class WebSocket extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._isClosed = false;
        this._page = parent;
        this._channel.on('frameSent', (event) => {
            const payload = event.opcode === 2 ? Buffer.from(event.data, 'base64') : event.data;
            this.emit(events_1.Events.WebSocket.FrameSent, { payload });
        });
        this._channel.on('frameReceived', (event) => {
            const payload = event.opcode === 2 ? Buffer.from(event.data, 'base64') : event.data;
            this.emit(events_1.Events.WebSocket.FrameReceived, { payload });
        });
        this._channel.on('socketError', ({ error }) => this.emit(events_1.Events.WebSocket.Error, error));
        this._channel.on('close', () => {
            this._isClosed = true;
            this.emit(events_1.Events.WebSocket.Close, this);
        });
    }
    static from(webSocket) {
        return webSocket._object;
    }
    url() {
        return this._initializer.url;
    }
    isClosed() {
        return this._isClosed;
    }
    async waitForEvent(event, optionsOrPredicate = {}) {
        const timeout = this._page._timeoutSettings.timeout(typeof optionsOrPredicate === 'function' ? {} : optionsOrPredicate);
        const predicate = typeof optionsOrPredicate === 'function' ? optionsOrPredicate : optionsOrPredicate.predicate;
        const waiter = waiter_1.Waiter.createForEvent(this, 'webSocket', event);
        waiter.rejectOnTimeout(timeout, `Timeout while waiting for event "${event}"`);
        if (event !== events_1.Events.WebSocket.Error)
            waiter.rejectOnEvent(this, events_1.Events.WebSocket.Error, new Error('Socket error'));
        if (event !== events_1.Events.WebSocket.Close)
            waiter.rejectOnEvent(this, events_1.Events.WebSocket.Close, new Error('Socket closed'));
        waiter.rejectOnEvent(this._page, events_1.Events.Page.Close, new Error('Page closed'));
        const result = await waiter.waitForEvent(this, event, predicate);
        waiter.dispose();
        return result;
    }
}
exports.WebSocket = WebSocket;
function validateHeaders(headers) {
    for (const key of Object.keys(headers)) {
        const value = headers[key];
        if (!Object.is(value, undefined) && !utils_1.isString(value))
            throw new Error(`Expected value of header "${key}" to be String, but "${typeof value}" is found.`);
    }
}
exports.validateHeaders = validateHeaders;
//# sourceMappingURL=network.js.map