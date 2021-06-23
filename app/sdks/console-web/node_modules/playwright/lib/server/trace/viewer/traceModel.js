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
exports.PersistentSnapshotStorage = exports.TraceModel = exports.trace = void 0;
const fs_1 = __importDefault(require("fs"));
const path_1 = __importDefault(require("path"));
const snapshotStorage_1 = require("../../snapshot/snapshotStorage");
exports.trace = __importStar(require("../common/traceEvents"));
class TraceModel {
    constructor(snapshotStorage) {
        this.pageEntries = new Map();
        this.contextResources = new Map();
        this._snapshotStorage = snapshotStorage;
        this.contextEntry = {
            startTime: Number.MAX_VALUE,
            endTime: Number.MIN_VALUE,
            browserName: '',
            options: { sdkLanguage: '' },
            pages: [],
            resources: []
        };
    }
    appendEvents(events, snapshotStorage) {
        for (const event of events)
            this.appendEvent(event);
        const actions = [];
        for (const page of this.contextEntry.pages)
            actions.push(...page.actions);
        this.contextEntry.resources = snapshotStorage.resources();
    }
    _pageEntry(pageId) {
        let pageEntry = this.pageEntries.get(pageId);
        if (!pageEntry) {
            pageEntry = {
                actions: [],
                events: [],
                screencastFrames: [],
            };
            this.pageEntries.set(pageId, pageEntry);
            this.contextEntry.pages.push(pageEntry);
        }
        return pageEntry;
    }
    appendEvent(event) {
        switch (event.type) {
            case 'context-options': {
                this.contextEntry.browserName = event.browserName;
                this.contextEntry.options = event.options;
                break;
            }
            case 'screencast-frame': {
                this._pageEntry(event.pageId).screencastFrames.push(event);
                break;
            }
            case 'action': {
                const metadata = event.metadata;
                if (metadata.pageId)
                    this._pageEntry(metadata.pageId).actions.push(event);
                break;
            }
            case 'event': {
                const metadata = event.metadata;
                if (metadata.pageId)
                    this._pageEntry(metadata.pageId).events.push(event);
                break;
            }
            case 'resource-snapshot':
                this._snapshotStorage.addResource(event.snapshot);
                break;
            case 'frame-snapshot':
                this._snapshotStorage.addFrameSnapshot(event.snapshot);
                break;
        }
        if (event.type === 'action' || event.type === 'event') {
            this.contextEntry.startTime = Math.min(this.contextEntry.startTime, event.metadata.startTime);
            this.contextEntry.endTime = Math.max(this.contextEntry.endTime, event.metadata.endTime);
        }
    }
}
exports.TraceModel = TraceModel;
class PersistentSnapshotStorage extends snapshotStorage_1.BaseSnapshotStorage {
    constructor(resourcesDir) {
        super();
        this._resourcesDir = resourcesDir;
    }
    resourceContent(sha1) {
        return fs_1.default.readFileSync(path_1.default.join(this._resourcesDir, sha1));
    }
}
exports.PersistentSnapshotStorage = PersistentSnapshotStorage;
//# sourceMappingURL=traceModel.js.map