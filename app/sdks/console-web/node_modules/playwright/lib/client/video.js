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
exports.Video = void 0;
class Video {
    constructor(page) {
        this._artifact = null;
        this._artifactCallback = (artifact) => { };
        this._isRemote = false;
        const browser = page.context()._browser;
        this._isRemote = !!browser && !!browser._remoteType;
        this._artifact = Promise.race([
            new Promise(f => this._artifactCallback = f),
            page._closedOrCrashedPromise.then(() => null),
        ]);
    }
    _artifactReady(artifact) {
        artifact._isRemote = this._isRemote;
        this._artifactCallback(artifact);
    }
    async path() {
        if (this._isRemote)
            throw new Error(`Path is not available when using browserType.connect(). Use saveAs() to save a local copy.`);
        const artifact = await this._artifact;
        if (!artifact)
            throw new Error('Page did not produce any video frames');
        return artifact._initializer.absolutePath;
    }
    async saveAs(path) {
        const artifact = await this._artifact;
        if (!artifact)
            throw new Error('Page did not produce any video frames');
        return artifact.saveAs(path);
    }
    async delete() {
        const artifact = await this._artifact;
        if (artifact)
            await artifact.delete();
    }
}
exports.Video = Video;
//# sourceMappingURL=video.js.map