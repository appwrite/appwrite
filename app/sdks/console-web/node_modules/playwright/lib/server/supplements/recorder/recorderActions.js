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
exports.actionTitle = void 0;
function actionTitle(action) {
    switch (action.name) {
        case 'openPage':
            return `Open new page`;
        case 'closePage':
            return `Close page`;
        case 'check':
            return `Check ${action.selector}`;
        case 'uncheck':
            return `Uncheck ${action.selector}`;
        case 'click': {
            if (action.clickCount === 1)
                return `Click ${action.selector}`;
            if (action.clickCount === 2)
                return `Double click ${action.selector}`;
            if (action.clickCount === 3)
                return `Triple click ${action.selector}`;
            return `${action.clickCount}× click`;
        }
        case 'fill':
            return `Fill ${action.selector}`;
        case 'setInputFiles':
            if (action.files.length === 0)
                return `Clear selected files`;
            else
                return `Upload ${action.files.join(', ')}`;
        case 'navigate':
            return `Go to ${action.url}`;
        case 'press':
            return `Press ${action.key}` + (action.modifiers ? ' with modifiers' : '');
        case 'select':
            return `Select ${action.options.join(', ')}`;
    }
}
exports.actionTitle = actionTitle;
//# sourceMappingURL=recorderActions.js.map