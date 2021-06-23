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
exports.BaseSnapshotStorage = void 0;
const events_1 = require("events");
const snapshotRenderer_1 = require("./snapshotRenderer");
class BaseSnapshotStorage extends events_1.EventEmitter {
    constructor() {
        super(...arguments);
        this._resources = [];
        this._resourceMap = new Map();
        this._frameSnapshots = new Map();
        this._contextResources = new Map();
    }
    clear() {
        this._resources = [];
        this._resourceMap.clear();
        this._frameSnapshots.clear();
        this._contextResources.clear();
    }
    addResource(resource) {
        this._resourceMap.set(resource.resourceId, resource);
        this._resources.push(resource);
        let resources = this._contextResources.get(resource.url);
        if (!resources) {
            resources = [];
            this._contextResources.set(resource.url, resources);
        }
        resources.push({ frameId: resource.frameId, resourceId: resource.resourceId });
    }
    addFrameSnapshot(snapshot) {
        let frameSnapshots = this._frameSnapshots.get(snapshot.frameId);
        if (!frameSnapshots) {
            frameSnapshots = {
                raw: [],
                renderer: [],
            };
            this._frameSnapshots.set(snapshot.frameId, frameSnapshots);
            if (snapshot.isMainFrame)
                this._frameSnapshots.set(snapshot.pageId, frameSnapshots);
        }
        frameSnapshots.raw.push(snapshot);
        const renderer = new snapshotRenderer_1.SnapshotRenderer(new Map(this._contextResources), frameSnapshots.raw, frameSnapshots.raw.length - 1);
        frameSnapshots.renderer.push(renderer);
        this.emit('snapshot', renderer);
    }
    resourceById(resourceId) {
        return this._resourceMap.get(resourceId);
    }
    resources() {
        return this._resources.slice();
    }
    snapshotByName(pageOrFrameId, snapshotName) {
        const snapshot = this._frameSnapshots.get(pageOrFrameId);
        return snapshot === null || snapshot === void 0 ? void 0 : snapshot.renderer.find(r => r.snapshotName === snapshotName);
    }
}
exports.BaseSnapshotStorage = BaseSnapshotStorage;
//# sourceMappingURL=snapshotStorage.js.map