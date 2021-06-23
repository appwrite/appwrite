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
exports.TraceSnapshotter = void 0;
const events_1 = require("events");
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const snapshotter_1 = require("../../snapshot/snapshotter");
class TraceSnapshotter extends events_1.EventEmitter {
    constructor(context, resourcesDir, appendTraceEvent) {
        super();
        this._writeArtifactChain = Promise.resolve();
        this._resourcesDir = resourcesDir;
        this._snapshotter = new snapshotter_1.Snapshotter(context, this);
        this._appendTraceEvent = appendTraceEvent;
        this._writeArtifactChain = Promise.resolve();
    }
    started() {
        return this._snapshotter.started();
    }
    async start() {
        await this._snapshotter.start();
    }
    async stop() {
        await this._snapshotter.stop();
        await this._writeArtifactChain;
    }
    async dispose() {
        this._snapshotter.dispose();
        await this._writeArtifactChain;
    }
    async captureSnapshot(page, snapshotName, element) {
        await this._snapshotter.captureSnapshot(page, snapshotName, element).catch(() => { });
    }
    onBlob(blob) {
        this._writeArtifactChain = this._writeArtifactChain.then(async () => {
            await fs_1.default.promises.writeFile(path_1.default.join(this._resourcesDir, blob.sha1), blob.buffer).catch(() => { });
        });
    }
    onResourceSnapshot(snapshot) {
        this._appendTraceEvent({ type: 'resource-snapshot', snapshot });
    }
    onFrameSnapshot(snapshot) {
        this._appendTraceEvent({ type: 'frame-snapshot', snapshot });
    }
}
exports.TraceSnapshotter = TraceSnapshotter;
//# sourceMappingURL=traceSnapshotter.js.map