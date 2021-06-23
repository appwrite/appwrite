"use strict";
/**
 * Copyright 2018 Google Inc. All rights reserved.
 * Modifications copyright (c) Microsoft Corporation.
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
exports.PipeTransport = void 0;
const utils_1 = require("../utils/utils");
const debugLogger_1 = require("../utils/debugLogger");
class PipeTransport {
    constructor(pipeWrite, pipeRead) {
        this._pendingMessage = '';
        this._waitForNextTask = utils_1.makeWaitForNextTask();
        this._closed = false;
        this._pipeWrite = pipeWrite;
        pipeRead.on('data', buffer => this._dispatch(buffer));
        pipeRead.on('close', () => {
            this._closed = true;
            if (this.onclose)
                this.onclose.call(null);
        });
        pipeRead.on('error', e => debugLogger_1.debugLogger.log('error', e));
        pipeWrite.on('error', e => debugLogger_1.debugLogger.log('error', e));
        this.onmessage = undefined;
        this.onclose = undefined;
    }
    send(message) {
        if (this._closed)
            throw new Error('Pipe has been closed');
        this._pipeWrite.write(JSON.stringify(message));
        this._pipeWrite.write('\0');
    }
    close() {
        throw new Error('unimplemented');
    }
    _dispatch(buffer) {
        let end = buffer.indexOf('\0');
        if (end === -1) {
            this._pendingMessage += buffer.toString();
            return;
        }
        const message = this._pendingMessage + buffer.toString(undefined, 0, end);
        this._waitForNextTask(() => {
            if (this.onmessage)
                this.onmessage.call(null, JSON.parse(message));
        });
        let start = end + 1;
        end = buffer.indexOf('\0', start);
        while (end !== -1) {
            const message = buffer.toString(undefined, start, end);
            this._waitForNextTask(() => {
                if (this.onmessage)
                    this.onmessage.call(null, JSON.parse(message));
            });
            start = end + 1;
            end = buffer.indexOf('\0', start);
        }
        this._pendingMessage = buffer.toString(undefined, start);
    }
}
exports.PipeTransport = PipeTransport;
//# sourceMappingURL=pipeTransport.js.map