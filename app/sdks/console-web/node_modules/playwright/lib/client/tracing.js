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
exports.Tracing = void 0;
const artifact_1 = require("./artifact");
class Tracing {
    constructor(channel) {
        this._context = channel;
    }
    async start(options = {}) {
        await this._context._wrapApiCall('tracing.start', async (channel) => {
            return await channel.tracingStart(options);
        });
    }
    async stop(options = {}) {
        await this._context._wrapApiCall('tracing.stop', async (channel) => {
            var _a;
            await channel.tracingStop();
            if (options.path) {
                const result = await channel.tracingExport();
                const artifact = artifact_1.Artifact.from(result.artifact);
                if ((_a = this._context.browser()) === null || _a === void 0 ? void 0 : _a._remoteType)
                    artifact._isRemote = true;
                await artifact.saveAs(options.path);
                await artifact.delete();
            }
        });
    }
}
exports.Tracing = Tracing;
//# sourceMappingURL=tracing.js.map