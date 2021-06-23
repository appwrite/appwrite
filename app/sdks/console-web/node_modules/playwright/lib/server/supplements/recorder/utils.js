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
exports.describeFrame = exports.toModifiers = exports.toClickOptions = void 0;
function toClickOptions(action) {
    let method = 'click';
    if (action.clickCount === 2)
        method = 'dblclick';
    const modifiers = toModifiers(action.modifiers);
    const options = {};
    if (action.button !== 'left')
        options.button = action.button;
    if (modifiers.length)
        options.modifiers = modifiers;
    if (action.clickCount > 2)
        options.clickCount = action.clickCount;
    return { method, options };
}
exports.toClickOptions = toClickOptions;
function toModifiers(modifiers) {
    const result = [];
    if (modifiers & 1)
        result.push('Alt');
    if (modifiers & 2)
        result.push('Control');
    if (modifiers & 4)
        result.push('Meta');
    if (modifiers & 8)
        result.push('Shift');
    return result;
}
exports.toModifiers = toModifiers;
function describeFrame(frame) {
    const page = frame._page;
    if (page.mainFrame() === frame)
        return { isMainFrame: true, frameUrl: frame.url() };
    const frames = page.frames().filter(f => f.name() === frame.name());
    if (frames.length === 1 && frames[0] === frame)
        return { isMainFrame: false, frameUrl: frame.url(), frameName: frame.name() };
    return { isMainFrame: false, frameUrl: frame.url() };
}
exports.describeFrame = describeFrame;
//# sourceMappingURL=utils.js.map