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
exports.TraceViewer = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const playwright_1 = require("../../playwright");
const traceModel_1 = require("./traceModel");
const httpServer_1 = require("../../../utils/httpServer");
const snapshotServer_1 = require("../../snapshot/snapshotServer");
const consoleApiSource = __importStar(require("../../../generated/consoleApiSource"));
const utils_1 = require("../../../utils/utils");
const instrumentation_1 = require("../../instrumentation");
const progress_1 = require("../../progress");
class TraceViewer {
    constructor(tracesDir, browserName) {
        this._browserName = browserName;
        const resourcesDir = path_1.default.join(tracesDir, 'resources');
        // Served by TraceServer
        // - "/tracemodel" - json with trace model.
        //
        // Served by TraceViewer
        // - "/traceviewer/..." - our frontend.
        // - "/file?filePath" - local files, used by sources tab.
        // - "/sha1/<sha1>" - trace resource bodies, used by network previews.
        //
        // Served by SnapshotServer
        // - "/resources/<resourceId>" - network resources from the trace.
        // - "/snapshot/" - root for snapshot frame.
        // - "/snapshot/pageId/..." - actual snapshot html.
        // - "/snapshot/service-worker.js" - service worker that intercepts snapshot resources
        //   and translates them into "/resources/<resourceId>".
        const actionTraces = fs_1.default.readdirSync(tracesDir).filter(name => name.endsWith('.trace'));
        const debugNames = actionTraces.map(name => {
            const tracePrefix = path_1.default.join(tracesDir, name.substring(0, name.indexOf('.trace')));
            return path_1.default.basename(tracePrefix);
        });
        this._server = new httpServer_1.HttpServer();
        const traceListHandler = (request, response) => {
            response.statusCode = 200;
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify(debugNames));
            return true;
        };
        this._server.routePath('/contexts', traceListHandler);
        const snapshotStorage = new traceModel_1.PersistentSnapshotStorage(resourcesDir);
        new snapshotServer_1.SnapshotServer(this._server, snapshotStorage);
        const traceModelHandler = (request, response) => {
            const debugName = request.url.substring('/context/'.length);
            const tracePrefix = path_1.default.join(tracesDir, debugName);
            snapshotStorage.clear();
            response.statusCode = 200;
            response.setHeader('Content-Type', 'application/json');
            (async () => {
                const traceContent = await fs_1.default.promises.readFile(tracePrefix + '.trace', 'utf8');
                const events = traceContent.split('\n').map(line => line.trim()).filter(line => !!line).map(line => JSON.parse(line));
                const model = new traceModel_1.TraceModel(snapshotStorage);
                model.appendEvents(events, snapshotStorage);
                response.end(JSON.stringify(model.contextEntry));
            })().catch(e => console.error(e));
            return true;
        };
        this._server.routePrefix('/context/', traceModelHandler);
        const traceViewerHandler = (request, response) => {
            const relativePath = request.url.substring('/traceviewer/'.length);
            const absolutePath = path_1.default.join(__dirname, '..', '..', '..', 'web', ...relativePath.split('/'));
            return this._server.serveFile(response, absolutePath);
        };
        this._server.routePrefix('/traceviewer/', traceViewerHandler);
        const fileHandler = (request, response) => {
            try {
                const url = new URL('http://localhost' + request.url);
                const search = url.search;
                if (search[0] !== '?')
                    return false;
                return this._server.serveFile(response, search.substring(1));
            }
            catch (e) {
                return false;
            }
        };
        this._server.routePath('/file', fileHandler);
        const sha1Handler = (request, response) => {
            const sha1 = request.url.substring('/sha1/'.length);
            if (sha1.includes('/'))
                return false;
            return this._server.serveFile(response, path_1.default.join(resourcesDir, sha1));
        };
        this._server.routePrefix('/sha1/', sha1Handler);
    }
    async show() {
        const urlPrefix = await this._server.start();
        const traceViewerPlaywright = playwright_1.createPlaywright(true);
        const args = [
            '--app=data:text/html,',
            '--window-size=1280,800'
        ];
        if (utils_1.isUnderTest())
            args.push(`--remote-debugging-port=0`);
        const context = await traceViewerPlaywright[this._browserName].launchPersistentContext(instrumentation_1.internalCallMetadata(), '', {
            // TODO: store language in the trace.
            sdkLanguage: 'javascript',
            args,
            noDefaultViewport: true,
            headless: !!process.env.PWTEST_CLI_HEADLESS,
            useWebSocket: utils_1.isUnderTest()
        });
        const controller = new progress_1.ProgressController(instrumentation_1.internalCallMetadata(), context._browser);
        await controller.run(async (progress) => {
            await context._browser._defaultContext._loadDefaultContextAsIs(progress);
        });
        await context.extendInjectedScript('main', consoleApiSource.source);
        const [page] = context.pages();
        page.on('close', () => process.exit(0));
        await page.mainFrame().goto(instrumentation_1.internalCallMetadata(), urlPrefix + '/traceviewer/traceViewer/index.html');
    }
}
exports.TraceViewer = TraceViewer;
//# sourceMappingURL=traceViewer.js.map