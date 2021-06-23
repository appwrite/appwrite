"use strict";
/**
 * Copyright 2017 Google Inc. All rights reserved.
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
exports.helper = void 0;
const debugLogger_1 = require("../utils/debugLogger");
class Helper {
    static addEventListener(emitter, eventName, handler) {
        emitter.on(eventName, handler);
        return { emitter, eventName, handler };
    }
    static removeEventListeners(listeners) {
        for (const listener of listeners)
            listener.emitter.removeListener(listener.eventName, listener.handler);
        listeners.splice(0, listeners.length);
    }
    static completeUserURL(urlString) {
        if (urlString.startsWith('localhost') || urlString.startsWith('127.0.0.1'))
            urlString = 'http://' + urlString;
        return urlString;
    }
    static enclosingIntRect(rect) {
        const x = Math.floor(rect.x + 1e-3);
        const y = Math.floor(rect.y + 1e-3);
        const x2 = Math.ceil(rect.x + rect.width - 1e-3);
        const y2 = Math.ceil(rect.y + rect.height - 1e-3);
        return { x, y, width: x2 - x, height: y2 - y };
    }
    static enclosingIntSize(size) {
        return { width: Math.floor(size.width + 1e-3), height: Math.floor(size.height + 1e-3) };
    }
    static getViewportSizeFromWindowFeatures(features) {
        const widthString = features.find(f => f.startsWith('width='));
        const heightString = features.find(f => f.startsWith('height='));
        const width = widthString ? parseInt(widthString.substring(6), 10) : NaN;
        const height = heightString ? parseInt(heightString.substring(7), 10) : NaN;
        if (!Number.isNaN(width) && !Number.isNaN(height))
            return { width, height };
        return null;
    }
    static waitForEvent(progress, emitter, event, predicate) {
        const listeners = [];
        const promise = new Promise((resolve, reject) => {
            listeners.push(exports.helper.addEventListener(emitter, event, eventArg => {
                try {
                    if (predicate && !predicate(eventArg))
                        return;
                    exports.helper.removeEventListeners(listeners);
                    resolve(eventArg);
                }
                catch (e) {
                    exports.helper.removeEventListeners(listeners);
                    reject(e);
                }
            }));
        });
        const dispose = () => exports.helper.removeEventListeners(listeners);
        if (progress)
            progress.cleanupWhenAborted(dispose);
        return { promise, dispose };
    }
    static secondsToRoundishMillis(value) {
        return ((value * 1000000) | 0) / 1000;
    }
    static millisToRoundishMillis(value) {
        return ((value * 1000) | 0) / 1000;
    }
    static debugProtocolLogger(protocolLogger) {
        return (direction, message) => {
            if (protocolLogger)
                protocolLogger(direction, message);
            if (debugLogger_1.debugLogger.isEnabled('protocol'))
                debugLogger_1.debugLogger.log('protocol', (direction === 'send' ? 'SEND ► ' : '◀ RECV ') + JSON.stringify(message));
        };
    }
    static formatBrowserLogs(logs) {
        if (!logs.length)
            return '';
        return '\n' + '='.repeat(20) + ' Browser output: ' + '='.repeat(20) + '\n' + logs.join('\n');
    }
}
exports.helper = Helper;
//# sourceMappingURL=helper.js.map