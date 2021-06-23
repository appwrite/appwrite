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
exports.toSignalMap = exports.sanitizeDeviceOptions = void 0;
function sanitizeDeviceOptions(device, options) {
    // Filter out all the properties from the device descriptor.
    const cleanedOptions = {};
    for (const property in options) {
        if (JSON.stringify(device[property]) !== JSON.stringify(options[property]))
            cleanedOptions[property] = options[property];
    }
    return cleanedOptions;
}
exports.sanitizeDeviceOptions = sanitizeDeviceOptions;
function toSignalMap(action) {
    let waitForNavigation;
    let assertNavigation;
    let popup;
    let download;
    let dialog;
    for (const signal of action.signals) {
        if (signal.name === 'navigation' && signal.isAsync)
            waitForNavigation = signal;
        else if (signal.name === 'navigation' && !signal.isAsync)
            assertNavigation = signal;
        else if (signal.name === 'popup')
            popup = signal;
        else if (signal.name === 'download')
            download = signal;
        else if (signal.name === 'dialog')
            dialog = signal;
    }
    return {
        waitForNavigation,
        assertNavigation,
        popup,
        download,
        dialog,
    };
}
exports.toSignalMap = toSignalMap;
//# sourceMappingURL=language.js.map