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
Object.defineProperty(exports, "__esModule", { value: true });
exports.mergeHeaders = exports.singleHeader = exports.STATUS_TEXTS = exports.WebSocket = exports.Response = exports.Route = exports.Request = exports.stripFragmentFromUrl = exports.parsedURL = exports.rewriteCookies = exports.filterCookies = void 0;
const utils_1 = require("../utils/utils");
const instrumentation_1 = require("./instrumentation");
function filterCookies(cookies, urls) {
    const parsedURLs = urls.map(s => new URL(s));
    // Chromiums's cookies are missing sameSite when it is 'None'
    return cookies.filter(c => {
        // Firefox and WebKit can return cookies with empty values.
        if (!c.value)
            return false;
        if (!parsedURLs.length)
            return true;
        for (const parsedURL of parsedURLs) {
            let domain = c.domain;
            if (!domain.startsWith('.'))
                domain = '.' + domain;
            if (!('.' + parsedURL.hostname).endsWith(domain))
                continue;
            if (!parsedURL.pathname.startsWith(c.path))
                continue;
            if (parsedURL.protocol !== 'https:' && c.secure)
                continue;
            return true;
        }
        return false;
    });
}
exports.filterCookies = filterCookies;
function rewriteCookies(cookies) {
    return cookies.map(c => {
        utils_1.assert(c.name, 'Cookie should have a name');
        utils_1.assert(c.value, 'Cookie should have a value');
        utils_1.assert(c.url || (c.domain && c.path), 'Cookie should have a url or a domain/path pair');
        utils_1.assert(!(c.url && c.domain), 'Cookie should have either url or domain');
        utils_1.assert(!(c.url && c.path), 'Cookie should have either url or domain');
        const copy = { ...c };
        if (copy.url) {
            utils_1.assert(copy.url !== 'about:blank', `Blank page can not have cookie "${c.name}"`);
            utils_1.assert(!copy.url.startsWith('data:'), `Data URL page can not have cookie "${c.name}"`);
            const url = new URL(copy.url);
            copy.domain = url.hostname;
            copy.path = url.pathname.substring(0, url.pathname.lastIndexOf('/') + 1);
            copy.secure = url.protocol === 'https:';
        }
        return copy;
    });
}
exports.rewriteCookies = rewriteCookies;
function parsedURL(url) {
    try {
        return new URL(url);
    }
    catch (e) {
        return null;
    }
}
exports.parsedURL = parsedURL;
function stripFragmentFromUrl(url) {
    if (!url.includes('#'))
        return url;
    return url.substring(0, url.indexOf('#'));
}
exports.stripFragmentFromUrl = stripFragmentFromUrl;
class Request extends instrumentation_1.SdkObject {
    constructor(routeDelegate, frame, redirectedFrom, documentId, url, resourceType, method, postData, headers) {
        super(frame, 'request');
        this._response = null;
        this._redirectedTo = null;
        this._failureText = null;
        this._headersMap = new Map();
        this._waitForResponsePromiseCallback = () => { };
        this._responseEndTiming = -1;
        utils_1.assert(!url.startsWith('data:'), 'Data urls should not fire requests');
        utils_1.assert(!(routeDelegate && redirectedFrom), 'Should not be able to intercept redirects');
        this._routeDelegate = routeDelegate;
        this._frame = frame;
        this._redirectedFrom = redirectedFrom;
        if (redirectedFrom)
            redirectedFrom._redirectedTo = this;
        this._documentId = documentId;
        this._url = stripFragmentFromUrl(url);
        this._resourceType = resourceType;
        this._method = method;
        this._postData = postData;
        this._headers = headers;
        for (const { name, value } of this._headers)
            this._headersMap.set(name.toLowerCase(), value);
        this._waitForResponsePromise = new Promise(f => this._waitForResponsePromiseCallback = f);
        this._isFavicon = url.endsWith('/favicon.ico');
    }
    _setFailureText(failureText) {
        this._failureText = failureText;
        this._waitForResponsePromiseCallback(null);
    }
    url() {
        return this._url;
    }
    resourceType() {
        return this._resourceType;
    }
    method() {
        return this._method;
    }
    postDataBuffer() {
        return this._postData;
    }
    headers() {
        return this._headers;
    }
    headerValue(name) {
        return this._headersMap.get(name);
    }
    response() {
        return this._waitForResponsePromise;
    }
    _existingResponse() {
        return this._response;
    }
    _setResponse(response) {
        this._response = response;
        this._waitForResponsePromiseCallback(response);
    }
    _finalRequest() {
        return this._redirectedTo ? this._redirectedTo._finalRequest() : this;
    }
    frame() {
        return this._frame;
    }
    isNavigationRequest() {
        return !!this._documentId;
    }
    redirectedFrom() {
        return this._redirectedFrom;
    }
    failure() {
        if (this._failureText === null)
            return null;
        return {
            errorText: this._failureText
        };
    }
    _route() {
        if (!this._routeDelegate)
            return null;
        return new Route(this, this._routeDelegate);
    }
    updateWithRawHeaders(headers) {
        this._headers = headers;
        this._headersMap.clear();
        for (const { name, value } of this._headers)
            this._headersMap.set(name.toLowerCase(), value);
        if (!this._headersMap.has('host')) {
            const host = new URL(this._url).host;
            this._headers.push({ name: 'host', value: host });
            this._headersMap.set('host', host);
        }
    }
}
exports.Request = Request;
class Route extends instrumentation_1.SdkObject {
    constructor(request, delegate) {
        super(request.frame(), 'route');
        this._handled = false;
        this._request = request;
        this._delegate = delegate;
    }
    request() {
        return this._request;
    }
    async abort(errorCode = 'failed') {
        utils_1.assert(!this._handled, 'Route is already handled!');
        this._handled = true;
        await this._delegate.abort(errorCode);
    }
    async fulfill(response) {
        utils_1.assert(!this._handled, 'Route is already handled!');
        this._handled = true;
        await this._delegate.fulfill({
            status: response.status === undefined ? 200 : response.status,
            headers: response.headers || [],
            body: response.body || '',
            isBase64: response.isBase64 || false,
        });
    }
    async continue(overrides = {}) {
        utils_1.assert(!this._handled, 'Route is already handled!');
        if (overrides.url) {
            const newUrl = new URL(overrides.url);
            const oldUrl = new URL(this._request.url());
            if (oldUrl.protocol !== newUrl.protocol)
                throw new Error('New URL must have same protocol as overridden URL');
        }
        await this._delegate.continue(overrides);
    }
}
exports.Route = Route;
class Response extends instrumentation_1.SdkObject {
    constructor(request, status, statusText, headers, timing, getResponseBodyCallback) {
        super(request.frame(), 'response');
        this._contentPromise = null;
        this._finishedPromiseCallback = () => { };
        this._headersMap = new Map();
        this._request = request;
        this._timing = timing;
        this._status = status;
        this._statusText = statusText;
        this._url = request.url();
        this._headers = headers;
        for (const { name, value } of this._headers)
            this._headersMap.set(name.toLowerCase(), value);
        this._getResponseBodyCallback = getResponseBodyCallback;
        this._finishedPromise = new Promise(f => {
            this._finishedPromiseCallback = f;
        });
        this._request._setResponse(this);
    }
    _requestFinished(responseEndTiming, error) {
        this._request._responseEndTiming = Math.max(responseEndTiming, this._timing.responseStart);
        this._finishedPromiseCallback({ error });
    }
    url() {
        return this._url;
    }
    status() {
        return this._status;
    }
    statusText() {
        return this._statusText;
    }
    headers() {
        return this._headers;
    }
    headerValue(name) {
        return this._headersMap.get(name);
    }
    finished() {
        return this._finishedPromise.then(({ error }) => error ? new Error(error) : null);
    }
    timing() {
        return this._timing;
    }
    body() {
        if (!this._contentPromise) {
            this._contentPromise = this._finishedPromise.then(async ({ error }) => {
                if (error)
                    throw new Error(error);
                return this._getResponseBodyCallback();
            });
        }
        return this._contentPromise;
    }
    request() {
        return this._request;
    }
    frame() {
        return this._request.frame();
    }
}
exports.Response = Response;
class WebSocket extends instrumentation_1.SdkObject {
    constructor(parent, url) {
        super(parent, 'ws');
        this._url = url;
    }
    url() {
        return this._url;
    }
    frameSent(opcode, data) {
        this.emit(WebSocket.Events.FrameSent, { opcode, data });
    }
    frameReceived(opcode, data) {
        this.emit(WebSocket.Events.FrameReceived, { opcode, data });
    }
    error(errorMessage) {
        this.emit(WebSocket.Events.SocketError, errorMessage);
    }
    closed() {
        this.emit(WebSocket.Events.Close);
    }
}
exports.WebSocket = WebSocket;
WebSocket.Events = {
    Close: 'close',
    SocketError: 'socketerror',
    FrameReceived: 'framereceived',
    FrameSent: 'framesent',
};
// List taken from https://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml with extra 306 and 418 codes.
exports.STATUS_TEXTS = {
    '100': 'Continue',
    '101': 'Switching Protocols',
    '102': 'Processing',
    '103': 'Early Hints',
    '200': 'OK',
    '201': 'Created',
    '202': 'Accepted',
    '203': 'Non-Authoritative Information',
    '204': 'No Content',
    '205': 'Reset Content',
    '206': 'Partial Content',
    '207': 'Multi-Status',
    '208': 'Already Reported',
    '226': 'IM Used',
    '300': 'Multiple Choices',
    '301': 'Moved Permanently',
    '302': 'Found',
    '303': 'See Other',
    '304': 'Not Modified',
    '305': 'Use Proxy',
    '306': 'Switch Proxy',
    '307': 'Temporary Redirect',
    '308': 'Permanent Redirect',
    '400': 'Bad Request',
    '401': 'Unauthorized',
    '402': 'Payment Required',
    '403': 'Forbidden',
    '404': 'Not Found',
    '405': 'Method Not Allowed',
    '406': 'Not Acceptable',
    '407': 'Proxy Authentication Required',
    '408': 'Request Timeout',
    '409': 'Conflict',
    '410': 'Gone',
    '411': 'Length Required',
    '412': 'Precondition Failed',
    '413': 'Payload Too Large',
    '414': 'URI Too Long',
    '415': 'Unsupported Media Type',
    '416': 'Range Not Satisfiable',
    '417': 'Expectation Failed',
    '418': 'I\'m a teapot',
    '421': 'Misdirected Request',
    '422': 'Unprocessable Entity',
    '423': 'Locked',
    '424': 'Failed Dependency',
    '425': 'Too Early',
    '426': 'Upgrade Required',
    '428': 'Precondition Required',
    '429': 'Too Many Requests',
    '431': 'Request Header Fields Too Large',
    '451': 'Unavailable For Legal Reasons',
    '500': 'Internal Server Error',
    '501': 'Not Implemented',
    '502': 'Bad Gateway',
    '503': 'Service Unavailable',
    '504': 'Gateway Timeout',
    '505': 'HTTP Version Not Supported',
    '506': 'Variant Also Negotiates',
    '507': 'Insufficient Storage',
    '508': 'Loop Detected',
    '510': 'Not Extended',
    '511': 'Network Authentication Required',
};
function singleHeader(name, value) {
    return [{ name, value }];
}
exports.singleHeader = singleHeader;
function mergeHeaders(headers) {
    const lowerCaseToValue = new Map();
    const lowerCaseToOriginalCase = new Map();
    for (const h of headers) {
        if (!h)
            continue;
        for (const { name, value } of h) {
            const lower = name.toLowerCase();
            lowerCaseToOriginalCase.set(lower, name);
            lowerCaseToValue.set(lower, value);
        }
    }
    const result = [];
    for (const [lower, value] of lowerCaseToValue)
        result.push({ name: lowerCaseToOriginalCase.get(lower), value });
    return result;
}
exports.mergeHeaders = mergeHeaders;
//# sourceMappingURL=network.js.map