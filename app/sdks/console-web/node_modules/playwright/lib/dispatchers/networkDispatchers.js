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
exports.WebSocketDispatcher = exports.RouteDispatcher = exports.ResponseDispatcher = exports.RequestDispatcher = void 0;
const network_1 = require("../server/network");
const dispatcher_1 = require("./dispatcher");
const frameDispatcher_1 = require("./frameDispatcher");
class RequestDispatcher extends dispatcher_1.Dispatcher {
    static from(scope, request) {
        const result = dispatcher_1.existingDispatcher(request);
        return result || new RequestDispatcher(scope, request);
    }
    static fromNullable(scope, request) {
        return request ? RequestDispatcher.from(scope, request) : undefined;
    }
    constructor(scope, request) {
        const postData = request.postDataBuffer();
        super(scope, request, 'Request', {
            frame: frameDispatcher_1.FrameDispatcher.from(scope, request.frame()),
            url: request.url(),
            resourceType: request.resourceType(),
            method: request.method(),
            postData: postData === null ? undefined : postData.toString('base64'),
            headers: request.headers(),
            isNavigationRequest: request.isNavigationRequest(),
            redirectedFrom: RequestDispatcher.fromNullable(scope, request.redirectedFrom()),
        });
    }
    async response() {
        return { response: dispatcher_1.lookupNullableDispatcher(await this._object.response()) };
    }
}
exports.RequestDispatcher = RequestDispatcher;
class ResponseDispatcher extends dispatcher_1.Dispatcher {
    static from(scope, response) {
        const result = dispatcher_1.existingDispatcher(response);
        return result || new ResponseDispatcher(scope, response);
    }
    static fromNullable(scope, response) {
        return response ? ResponseDispatcher.from(scope, response) : undefined;
    }
    constructor(scope, response) {
        super(scope, response, 'Response', {
            // TODO: responses in popups can point to non-reported requests.
            request: RequestDispatcher.from(scope, response.request()),
            url: response.url(),
            status: response.status(),
            statusText: response.statusText(),
            requestHeaders: response.request().headers(),
            headers: response.headers(),
            timing: response.timing()
        });
    }
    async finished() {
        return await this._object._finishedPromise;
    }
    async body() {
        return { binary: (await this._object.body()).toString('base64') };
    }
}
exports.ResponseDispatcher = ResponseDispatcher;
class RouteDispatcher extends dispatcher_1.Dispatcher {
    static from(scope, route) {
        const result = dispatcher_1.existingDispatcher(route);
        return result || new RouteDispatcher(scope, route);
    }
    static fromNullable(scope, route) {
        return route ? RouteDispatcher.from(scope, route) : undefined;
    }
    constructor(scope, route) {
        super(scope, route, 'Route', {
            // Context route can point to a non-reported request.
            request: RequestDispatcher.from(scope, route.request())
        });
    }
    async continue(params) {
        await this._object.continue({
            url: params.url,
            method: params.method,
            headers: params.headers,
            postData: params.postData ? Buffer.from(params.postData, 'base64') : undefined,
        });
    }
    async fulfill(params) {
        await this._object.fulfill(params);
    }
    async abort(params) {
        await this._object.abort(params.errorCode || 'failed');
    }
}
exports.RouteDispatcher = RouteDispatcher;
class WebSocketDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, webSocket) {
        super(scope, webSocket, 'WebSocket', {
            url: webSocket.url(),
        });
        webSocket.on(network_1.WebSocket.Events.FrameSent, (event) => this._dispatchEvent('frameSent', event));
        webSocket.on(network_1.WebSocket.Events.FrameReceived, (event) => this._dispatchEvent('frameReceived', event));
        webSocket.on(network_1.WebSocket.Events.SocketError, (error) => this._dispatchEvent('socketError', { error }));
        webSocket.on(network_1.WebSocket.Events.Close, () => this._dispatchEvent('close', {}));
    }
}
exports.WebSocketDispatcher = WebSocketDispatcher;
//# sourceMappingURL=networkDispatchers.js.map