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
exports.Download = void 0;
class Download {
    constructor(page, url, suggestedFilename, artifact) {
        this._page = page;
        this._url = url;
        this._suggestedFilename = suggestedFilename;
        this._artifact = artifact;
    }
    page() {
        return this._page;
    }
    url() {
        return this._url;
    }
    suggestedFilename() {
        return this._suggestedFilename;
    }
    async path() {
        return this._artifact.pathAfterFinished();
    }
    async saveAs(path) {
        return this._artifact.saveAs(path);
    }
    async failure() {
        return this._artifact.failure();
    }
    async createReadStream() {
        return this._artifact.createReadStream();
    }
    async delete() {
        return this._artifact.delete();
    }
}
exports.Download = Download;
//# sourceMappingURL=download.js.map