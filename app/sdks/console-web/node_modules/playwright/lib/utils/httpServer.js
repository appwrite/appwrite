"use strict";
/**
 * Copyright (c) Microsoft Corporation.
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
exports.HttpServer = void 0;
const http = __importStar(require("http"));
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
class HttpServer {
    constructor() {
        this._routes = [];
        this._urlPrefix = '';
    }
    routePrefix(prefix, handler) {
        this._routes.push({ prefix, handler });
    }
    routePath(path, handler) {
        this._routes.push({ exact: path, handler });
    }
    async start(port) {
        this._server = http.createServer(this._onRequest.bind(this));
        this._server.listen(port);
        await new Promise(cb => this._server.once('listening', cb));
        const address = this._server.address();
        this._urlPrefix = typeof address === 'string' ? address : `http://127.0.0.1:${address.port}`;
        return this._urlPrefix;
    }
    async stop() {
        await new Promise(cb => this._server.close(cb));
    }
    urlPrefix() {
        return this._urlPrefix;
    }
    serveFile(response, absoluteFilePath, headers) {
        try {
            const content = fs_1.default.readFileSync(absoluteFilePath);
            response.statusCode = 200;
            const contentType = extensionToMime[path_1.default.extname(absoluteFilePath).substring(1)] || 'application/octet-stream';
            response.setHeader('Content-Type', contentType);
            response.setHeader('Content-Length', content.byteLength);
            for (const [name, value] of Object.entries(headers || {}))
                response.setHeader(name, value);
            response.end(content);
            return true;
        }
        catch (e) {
            return false;
        }
    }
    _onRequest(request, response) {
        request.on('error', () => response.end());
        try {
            if (!request.url) {
                response.end();
                return;
            }
            const url = new URL('http://localhost' + request.url);
            for (const route of this._routes) {
                if (route.exact && url.pathname === route.exact && route.handler(request, response))
                    return;
                if (route.prefix && url.pathname.startsWith(route.prefix) && route.handler(request, response))
                    return;
            }
            response.statusCode = 404;
            response.end();
        }
        catch (e) {
            response.end();
        }
    }
}
exports.HttpServer = HttpServer;
const extensionToMime = {
    'css': 'text/css',
    'html': 'text/html',
    'jpeg': 'image/jpeg',
    'jpg': 'image/jpeg',
    'js': 'application/javascript',
    'png': 'image/png',
    'ttf': 'font/ttf',
    'svg': 'image/svg+xml',
    'webp': 'image/webp',
    'woff': 'font/woff',
    'woff2': 'font/woff2',
};
//# sourceMappingURL=httpServer.js.map