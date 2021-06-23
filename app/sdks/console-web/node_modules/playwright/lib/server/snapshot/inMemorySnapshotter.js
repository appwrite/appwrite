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
Object.defineProperty(exports, "__esModule", { value: true });
exports.InMemorySnapshotter = void 0;
const httpServer_1 = require("../../utils/httpServer");
const helper_1 = require("../helper");
const snapshotServer_1 = require("./snapshotServer");
const snapshotStorage_1 = require("./snapshotStorage");
const snapshotter_1 = require("./snapshotter");
class InMemorySnapshotter extends snapshotStorage_1.BaseSnapshotStorage {
    constructor(context) {
        super();
        this._blobs = new Map();
        this._server = new httpServer_1.HttpServer();
        new snapshotServer_1.SnapshotServer(this._server, this);
        this._snapshotter = new snapshotter_1.Snapshotter(context, this);
    }
    async initialize() {
        await this._snapshotter.start();
        return await this._server.start();
    }
    async dispose() {
        this._snapshotter.dispose();
        await this._server.stop();
    }
    async captureSnapshot(page, snapshotName, element) {
        if (this._frameSnapshots.has(snapshotName))
            throw new Error('Duplicate snapshot name: ' + snapshotName);
        this._snapshotter.captureSnapshot(page, snapshotName, element).catch(() => { });
        return new Promise(fulfill => {
            const listener = helper_1.helper.addEventListener(this, 'snapshot', (renderer) => {
                if (renderer.snapshotName === snapshotName) {
                    helper_1.helper.removeEventListeners([listener]);
                    fulfill(renderer);
                }
            });
        });
    }
    onBlob(blob) {
        this._blobs.set(blob.sha1, blob.buffer);
    }
    onResourceSnapshot(resource) {
        this.addResource(resource);
    }
    onFrameSnapshot(snapshot) {
        this.addFrameSnapshot(snapshot);
    }
    resourceContent(sha1) {
        return this._blobs.get(sha1);
    }
}
exports.InMemorySnapshotter = InMemorySnapshotter;
//# sourceMappingURL=inMemorySnapshotter.js.map