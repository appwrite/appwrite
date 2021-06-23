"use strict";
/**
 * Copyright (c) Microsoft Corporation.
 *
 * Licensed under the Apache License, Version 2.0 (the 'License");
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
exports.DialogDispatcher = void 0;
const dispatcher_1 = require("./dispatcher");
class DialogDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, dialog) {
        super(scope, dialog, 'Dialog', {
            type: dialog.type(),
            message: dialog.message(),
            defaultValue: dialog.defaultValue(),
        });
    }
    async accept(params) {
        await this._object.accept(params.promptText);
    }
    async dismiss() {
        await this._object.dismiss();
    }
}
exports.DialogDispatcher = DialogDispatcher;
//# sourceMappingURL=dialogDispatcher.js.map