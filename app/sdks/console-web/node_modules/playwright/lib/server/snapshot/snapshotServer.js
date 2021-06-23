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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.SnapshotServer = void 0;
const querystring_1 = __importDefault(require("querystring"));
class SnapshotServer {
    constructor(server, snapshotStorage) {
        this._snapshotStorage = snapshotStorage;
        server.routePrefix('/snapshot/', this._serveSnapshot.bind(this));
        server.routePrefix('/resources/', this._serveResource.bind(this));
    }
    _serveSnapshotRoot(request, response) {
        response.statusCode = 200;
        response.setHeader('Cache-Control', 'public, max-age=31536000');
        response.setHeader('Content-Type', 'text/html');
        response.end(`
      <style>
        html, body {
          margin: 0;
          padding: 0;
        }
        iframe {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          border: none;
        }
      </style>
      <body>
        <script>
        (${rootScript})();
        </script>
      </body>
    `);
        return true;
    }
    _serveServiceWorker(request, response) {
        function serviceWorkerMain(self /* ServiceWorkerGlobalScope */) {
            const snapshotResources = new Map();
            self.addEventListener('install', function (event) {
            });
            self.addEventListener('activate', function (event) {
                event.waitUntil(self.clients.claim());
            });
            function respond404() {
                return new Response(null, { status: 404 });
            }
            function respondNotAvailable() {
                return new Response('<body style="background: #ddd"></body>', { status: 200, headers: { 'Content-Type': 'text/html' } });
            }
            function removeHash(url) {
                try {
                    const u = new URL(url);
                    u.hash = '';
                    return u.toString();
                }
                catch (e) {
                    return url;
                }
            }
            async function doFetch(event /* FetchEvent */) {
                const request = event.request;
                const pathname = new URL(request.url).pathname;
                if (pathname === '/snapshot/service-worker.js' || pathname === '/snapshot/')
                    return fetch(event.request);
                const snapshotUrl = request.mode === 'navigate' ?
                    request.url : (await self.clients.get(event.clientId)).url;
                if (request.mode === 'navigate') {
                    const htmlResponse = await fetch(event.request);
                    const { html, resources } = await htmlResponse.json();
                    if (!html)
                        return respondNotAvailable();
                    snapshotResources.set(snapshotUrl, resources);
                    const response = new Response(html, { status: 200, headers: { 'Content-Type': 'text/html' } });
                    return response;
                }
                const resources = snapshotResources.get(snapshotUrl);
                const urlWithoutHash = removeHash(request.url);
                const resource = resources[urlWithoutHash];
                if (!resource)
                    return respond404();
                const fetchUrl = resource.sha1 ?
                    `/resources/${resource.resourceId}/override/${resource.sha1}` :
                    `/resources/${resource.resourceId}`;
                const fetchedResponse = await fetch(fetchUrl);
                const headers = new Headers(fetchedResponse.headers);
                // We make a copy of the response, instead of just forwarding,
                // so that response url is not inherited as "/resources/...", but instead
                // as the original request url.
                // Response url turns into resource base uri that is used to resolve
                // relative links, e.g. url(/foo/bar) in style sheets.
                if (resource.sha1) {
                    // No cache, so that we refetch overridden resources.
                    headers.set('Cache-Control', 'no-cache');
                }
                const response = new Response(fetchedResponse.body, {
                    status: fetchedResponse.status,
                    statusText: fetchedResponse.statusText,
                    headers,
                });
                return response;
            }
            self.addEventListener('fetch', function (event) {
                event.respondWith(doFetch(event));
            });
        }
        response.statusCode = 200;
        response.setHeader('Cache-Control', 'public, max-age=31536000');
        response.setHeader('Content-Type', 'application/javascript');
        response.end(`(${serviceWorkerMain.toString()})(self)`);
        return true;
    }
    _serveSnapshot(request, response) {
        if (request.url.endsWith('/snapshot/'))
            return this._serveSnapshotRoot(request, response);
        if (request.url.endsWith('/snapshot/service-worker.js'))
            return this._serveServiceWorker(request, response);
        response.statusCode = 200;
        response.setHeader('Cache-Control', 'public, max-age=31536000');
        response.setHeader('Content-Type', 'application/json');
        const [pageOrFrameId, query] = request.url.substring('/snapshot/'.length).split('?');
        const parsed = querystring_1.default.parse(query);
        const snapshot = this._snapshotStorage.snapshotByName(pageOrFrameId, parsed.name);
        const snapshotData = snapshot ? snapshot.render() : { html: '' };
        response.end(JSON.stringify(snapshotData));
        return true;
    }
    _serveResource(request, response) {
        // - /resources/<resourceId>
        // - /resources/<resourceId>/override/<overrideSha1>
        const parts = request.url.split('/');
        if (!parts[0])
            parts.shift();
        if (!parts[parts.length - 1])
            parts.pop();
        if (parts[0] !== 'resources')
            return false;
        let resourceId;
        let overrideSha1;
        if (parts.length === 2) {
            resourceId = parts[1];
        }
        else if (parts.length === 4 && parts[2] === 'override') {
            resourceId = parts[1];
            overrideSha1 = parts[3];
        }
        else {
            return false;
        }
        const resource = this._snapshotStorage.resourceById(resourceId);
        if (!resource)
            return false;
        const sha1 = overrideSha1 || resource.responseSha1;
        try {
            const content = this._snapshotStorage.resourceContent(sha1);
            if (!content)
                return false;
            response.statusCode = 200;
            let contentType = resource.contentType;
            const isTextEncoding = /^text\/|^application\/(javascript|json)/.test(contentType);
            if (isTextEncoding && !contentType.includes('charset'))
                contentType = `${contentType}; charset=utf-8`;
            response.setHeader('Content-Type', contentType);
            for (const { name, value } of resource.responseHeaders)
                response.setHeader(name, value);
            response.removeHeader('Content-Encoding');
            response.removeHeader('Access-Control-Allow-Origin');
            response.setHeader('Access-Control-Allow-Origin', '*');
            response.removeHeader('Content-Length');
            response.setHeader('Content-Length', content.byteLength);
            response.setHeader('Cache-Control', 'public, max-age=31536000');
            response.end(content);
            return true;
        }
        catch (e) {
            return false;
        }
    }
}
exports.SnapshotServer = SnapshotServer;
function rootScript() {
    if (!navigator.serviceWorker)
        return;
    navigator.serviceWorker.register('./service-worker.js');
    let showPromise = Promise.resolve();
    if (!navigator.serviceWorker.controller) {
        showPromise = new Promise(resolve => {
            navigator.serviceWorker.oncontrollerchange = () => resolve();
        });
    }
    const pointElement = document.createElement('div');
    pointElement.style.position = 'fixed';
    pointElement.style.backgroundColor = 'red';
    pointElement.style.width = '20px';
    pointElement.style.height = '20px';
    pointElement.style.borderRadius = '10px';
    pointElement.style.margin = '-10px 0 0 -10px';
    pointElement.style.zIndex = '2147483647';
    const iframe = document.createElement('iframe');
    document.body.appendChild(iframe);
    window.showSnapshot = async (url, options = {}) => {
        await showPromise;
        iframe.src = url;
        if (options.point) {
            pointElement.style.left = options.point.x + 'px';
            pointElement.style.top = options.point.y + 'px';
            document.documentElement.appendChild(pointElement);
        }
        else {
            pointElement.remove();
        }
    };
    window.addEventListener('message', event => {
        window.showSnapshot(window.location.href + event.data.snapshotUrl);
    }, false);
}
//# sourceMappingURL=snapshotServer.js.map