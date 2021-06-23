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
exports.metadataToCallLog = void 0;
function metadataToCallLog(metadata, status) {
    var _a, _b;
    const title = metadata.apiName || metadata.method;
    if (metadata.error)
        status = 'error';
    const params = {
        url: (_a = metadata.params) === null || _a === void 0 ? void 0 : _a.url,
        selector: (_b = metadata.params) === null || _b === void 0 ? void 0 : _b.selector,
    };
    let duration = metadata.endTime ? metadata.endTime - metadata.startTime : undefined;
    if (typeof duration === 'number' && metadata.pauseStartTime && metadata.pauseEndTime) {
        duration -= (metadata.pauseEndTime - metadata.pauseStartTime);
        duration = Math.max(duration, 0);
    }
    const callLog = {
        id: metadata.id,
        messages: metadata.log,
        title,
        status,
        error: metadata.error,
        params,
        duration,
    };
    return callLog;
}
exports.metadataToCallLog = metadataToCallLog;
//# sourceMappingURL=recorderUtils.js.map