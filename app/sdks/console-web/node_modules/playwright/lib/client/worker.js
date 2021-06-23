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
exports.Worker = void 0;
const events_1 = require("./events");
const channelOwner_1 = require("./channelOwner");
const jsHandle_1 = require("./jsHandle");
class Worker extends channelOwner_1.ChannelOwner {
    constructor(parent, type, guid, initializer) {
        super(parent, type, guid, initializer);
        this._channel.on('close', () => {
            if (this._page)
                this._page._workers.delete(this);
            if (this._context)
                this._context._serviceWorkers.delete(this);
            this.emit(events_1.Events.Worker.Close, this);
        });
    }
    static from(worker) {
        return worker._object;
    }
    url() {
        return this._initializer.url;
    }
    async evaluate(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._wrapApiCall('worker.evaluate', async (channel) => {
            const result = await channel.evaluateExpression({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.parseResult(result.value);
        });
    }
    async evaluateHandle(pageFunction, arg) {
        jsHandle_1.assertMaxArguments(arguments.length, 2);
        return this._wrapApiCall('worker.evaluateHandle', async (channel) => {
            const result = await channel.evaluateExpressionHandle({ expression: String(pageFunction), isFunction: typeof pageFunction === 'function', arg: jsHandle_1.serializeArgument(arg) });
            return jsHandle_1.JSHandle.from(result.handle);
        });
    }
}
exports.Worker = Worker;
//# sourceMappingURL=worker.js.map