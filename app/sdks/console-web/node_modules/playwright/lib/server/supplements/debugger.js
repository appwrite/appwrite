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
exports.Debugger = void 0;
const events_1 = require("events");
const utils_1 = require("../../utils/utils");
const debugLogger_1 = require("../../utils/debugLogger");
const symbol = Symbol('Debugger');
class Debugger extends events_1.EventEmitter {
    constructor(context) {
        super();
        this._pauseOnNextStatement = false;
        this._pausedCallsMetadata = new Map();
        this._muted = false;
        this._context = context;
        this._context[symbol] = this;
        this._enabled = utils_1.debugMode() === 'inspector';
        if (this._enabled)
            this.pauseOnNextStatement();
    }
    static lookup(context) {
        if (!context)
            return;
        return context[symbol];
    }
    async setMuted(muted) {
        this._muted = muted;
    }
    async onBeforeCall(sdkObject, metadata) {
        if (this._muted)
            return;
        if (shouldPauseOnCall(sdkObject, metadata) || (this._pauseOnNextStatement && shouldPauseOnNonInputStep(sdkObject, metadata)))
            await this.pause(sdkObject, metadata);
    }
    async onBeforeInputAction(sdkObject, metadata) {
        if (this._muted)
            return;
        if (this._enabled && this._pauseOnNextStatement)
            await this.pause(sdkObject, metadata);
    }
    async onCallLog(logName, message, sdkObject, metadata) {
        debugLogger_1.debugLogger.log(logName, message);
    }
    async pause(sdkObject, metadata) {
        if (this._muted)
            return;
        this._enabled = true;
        metadata.pauseStartTime = utils_1.monotonicTime();
        const result = new Promise(resolve => {
            this._pausedCallsMetadata.set(metadata, { resolve, sdkObject });
        });
        this.emit(Debugger.Events.PausedStateChanged);
        return result;
    }
    resume(step) {
        this._pauseOnNextStatement = step;
        const endTime = utils_1.monotonicTime();
        for (const [metadata, { resolve }] of this._pausedCallsMetadata) {
            metadata.pauseEndTime = endTime;
            resolve();
        }
        this._pausedCallsMetadata.clear();
        this.emit(Debugger.Events.PausedStateChanged);
    }
    pauseOnNextStatement() {
        this._pauseOnNextStatement = true;
    }
    isPaused(metadata) {
        if (metadata)
            return this._pausedCallsMetadata.has(metadata);
        return !!this._pausedCallsMetadata.size;
    }
    pausedDetails() {
        const result = [];
        for (const [metadata, { sdkObject }] of this._pausedCallsMetadata)
            result.push({ metadata, sdkObject });
        return result;
    }
}
exports.Debugger = Debugger;
Debugger.Events = {
    PausedStateChanged: 'pausedstatechanged'
};
function shouldPauseOnCall(sdkObject, metadata) {
    var _a;
    if (!((_a = sdkObject.attribution.browser) === null || _a === void 0 ? void 0 : _a.options.headful) && !utils_1.isUnderTest())
        return false;
    return metadata.method === 'pause';
}
const nonInputActionsToStep = new Set(['close', 'evaluate', 'evaluateHandle', 'goto', 'setContent']);
function shouldPauseOnNonInputStep(sdkObject, metadata) {
    return nonInputActionsToStep.has(metadata.method);
}
//# sourceMappingURL=debugger.js.map