"use strict";
/**
 * Copyright (c) Microsoft Corporation. All rights reserved.
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
exports.internalCallMetadata = exports.createInstrumentation = exports.SdkObject = void 0;
const events_1 = require("events");
const utils_1 = require("../utils/utils");
class SdkObject extends events_1.EventEmitter {
    constructor(parent, guidPrefix, guid) {
        super();
        this.guid = guid || `${guidPrefix || ''}@${utils_1.createGuid()}`;
        this.setMaxListeners(0);
        this.attribution = { ...parent.attribution };
        this.instrumentation = parent.instrumentation;
    }
}
exports.SdkObject = SdkObject;
function createInstrumentation() {
    const listeners = [];
    return new Proxy({}, {
        get: (obj, prop) => {
            if (prop === 'addListener')
                return (listener) => listeners.push(listener);
            if (prop === 'removeListener')
                return (listener) => listeners.splice(listeners.indexOf(listener), 1);
            if (!prop.startsWith('on'))
                return obj[prop];
            return async (...params) => {
                var _a, _b;
                for (const listener of listeners)
                    await ((_b = (_a = listener)[prop]) === null || _b === void 0 ? void 0 : _b.call(_a, ...params));
            };
        },
    });
}
exports.createInstrumentation = createInstrumentation;
function internalCallMetadata() {
    return {
        id: '',
        startTime: 0,
        endTime: 0,
        type: 'Internal',
        method: '',
        params: {},
        log: [],
        snapshots: []
    };
}
exports.internalCallMetadata = internalCallMetadata;
//# sourceMappingURL=instrumentation.js.map