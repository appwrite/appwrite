"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
var __importDefault = (this && this.__importDefault) || function (mod) {
    return (mod && mod.__esModule) ? mod : { "default": mod };
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.ArtifactDispatcher = void 0;
const dispatcher_1 = require("./dispatcher");
const streamDispatcher_1 = require("./streamDispatcher");
const fs_1 = __importDefault(require("fs"));
const utils_1 = require("../utils/utils");
class ArtifactDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, artifact) {
        super(scope, artifact, 'Artifact', {
            absolutePath: artifact.localPath(),
        });
    }
    async pathAfterFinished() {
        const path = await this._object.localPathAfterFinished();
        return { value: path || undefined };
    }
    async saveAs(params) {
        return await new Promise((resolve, reject) => {
            this._object.saveAs(async (localPath, error) => {
                if (error !== undefined) {
                    reject(new Error(error));
                    return;
                }
                try {
                    await utils_1.mkdirIfNeeded(params.path);
                    await fs_1.default.promises.copyFile(localPath, params.path);
                    resolve();
                }
                catch (e) {
                    reject(e);
                }
            });
        });
    }
    async saveAsStream() {
        return await new Promise((resolve, reject) => {
            this._object.saveAs(async (localPath, error) => {
                if (error !== undefined) {
                    reject(new Error(error));
                    return;
                }
                try {
                    const readable = fs_1.default.createReadStream(localPath);
                    const stream = new streamDispatcher_1.StreamDispatcher(this._scope, readable);
                    // Resolve with a stream, so that client starts saving the data.
                    resolve({ stream });
                    // Block the Artifact until the stream is consumed.
                    await new Promise(resolve => {
                        readable.on('close', resolve);
                        readable.on('end', resolve);
                        readable.on('error', resolve);
                    });
                }
                catch (e) {
                    reject(e);
                }
            });
        });
    }
    async stream() {
        const fileName = await this._object.localPathAfterFinished();
        if (!fileName)
            return {};
        const readable = fs_1.default.createReadStream(fileName);
        return { stream: new streamDispatcher_1.StreamDispatcher(this._scope, readable) };
    }
    async failure() {
        const error = await this._object.failureError();
        return { error: error || undefined };
    }
    async delete() {
        await this._object.delete();
        this._dispose();
    }
}
exports.ArtifactDispatcher = ArtifactDispatcher;
//# sourceMappingURL=artifactDispatcher.js.map