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
Object.defineProperty(exports, "__esModule", { value: true });
exports.Artifact = void 0;
const fs = __importStar(require("fs"));
const stream_1 = require("./stream");
const utils_1 = require("../utils/utils");
const channelOwner_1 = require("./channelOwner");
class Artifact extends channelOwner_1.ChannelOwner {
    constructor() {
        super(...arguments);
        this._isRemote = false;
        this._apiName = '';
    }
    static from(channel) {
        return channel._object;
    }
    async pathAfterFinished() {
        if (this._isRemote)
            throw new Error(`Path is not available when using browserType.connect(). Use saveAs() to save a local copy.`);
        return this._wrapApiCall(`${this._apiName}.path`, async (channel) => {
            return (await channel.pathAfterFinished()).value || null;
        });
    }
    async saveAs(path) {
        return this._wrapApiCall(`${this._apiName}.saveAs`, async (channel) => {
            if (!this._isRemote) {
                await channel.saveAs({ path });
                return;
            }
            const result = await channel.saveAsStream();
            const stream = stream_1.Stream.from(result.stream);
            await utils_1.mkdirIfNeeded(path);
            await new Promise((resolve, reject) => {
                stream.stream().pipe(fs.createWriteStream(path))
                    .on('finish', resolve)
                    .on('error', reject);
            });
        });
    }
    async failure() {
        return this._wrapApiCall(`${this._apiName}.failure`, async (channel) => {
            return (await channel.failure()).error || null;
        });
    }
    async createReadStream() {
        return this._wrapApiCall(`${this._apiName}.createReadStream`, async (channel) => {
            const result = await channel.stream();
            if (!result.stream)
                return null;
            const stream = stream_1.Stream.from(result.stream);
            return stream.stream();
        });
    }
    async delete() {
        return this._wrapApiCall(`${this._apiName}.delete`, async (channel) => {
            return channel.delete();
        });
    }
}
exports.Artifact = Artifact;
//# sourceMappingURL=artifact.js.map