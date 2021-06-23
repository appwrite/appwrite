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
exports.Artifact = void 0;
const fs_1 = __importDefault(require("fs"));
const instrumentation_1 = require("./instrumentation");
class Artifact extends instrumentation_1.SdkObject {
    constructor(parent, localPath, unaccessibleErrorMessage) {
        super(parent, 'artifact');
        this._saveCallbacks = [];
        this._finished = false;
        this._deleted = false;
        this._failureError = null;
        this._localPath = localPath;
        this._unaccessibleErrorMessage = unaccessibleErrorMessage;
        this._finishedCallback = () => { };
        this._finishedPromise = new Promise(f => this._finishedCallback = f);
    }
    finishedPromise() {
        return this._finishedPromise;
    }
    localPath() {
        return this._localPath;
    }
    async localPathAfterFinished() {
        if (this._unaccessibleErrorMessage)
            throw new Error(this._unaccessibleErrorMessage);
        await this._finishedPromise;
        if (this._failureError)
            return null;
        return this._localPath;
    }
    saveAs(saveCallback) {
        if (this._unaccessibleErrorMessage)
            throw new Error(this._unaccessibleErrorMessage);
        if (this._deleted)
            throw new Error(`File already deleted. Save before deleting.`);
        if (this._failureError)
            throw new Error(`File not found on disk. Check download.failure() for details.`);
        if (this._finished) {
            saveCallback(this._localPath).catch(e => { });
            return;
        }
        this._saveCallbacks.push(saveCallback);
    }
    async failureError() {
        if (this._unaccessibleErrorMessage)
            return this._unaccessibleErrorMessage;
        await this._finishedPromise;
        return this._failureError;
    }
    async delete() {
        if (this._unaccessibleErrorMessage)
            return;
        const fileName = await this.localPathAfterFinished();
        if (this._deleted)
            return;
        this._deleted = true;
        if (fileName)
            await fs_1.default.promises.unlink(fileName).catch(e => { });
    }
    async deleteOnContextClose() {
        // Compared to "delete", this method does not wait for the artifact to finish.
        // We use it when closing the context to avoid stalling.
        if (this._deleted)
            return;
        this._deleted = true;
        if (!this._unaccessibleErrorMessage)
            await fs_1.default.promises.unlink(this._localPath).catch(e => { });
        await this.reportFinished('File deleted upon browser context closure.');
    }
    async reportFinished(error) {
        if (this._finished)
            return;
        this._finished = true;
        this._failureError = error || null;
        if (error) {
            for (const callback of this._saveCallbacks)
                await callback('', error);
        }
        else {
            for (const callback of this._saveCallbacks)
                await callback(this._localPath);
        }
        this._saveCallbacks = [];
        this._finishedCallback();
    }
}
exports.Artifact = Artifact;
//# sourceMappingURL=artifact.js.map