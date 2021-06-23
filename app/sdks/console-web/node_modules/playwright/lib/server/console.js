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
exports.ConsoleMessage = void 0;
const instrumentation_1 = require("./instrumentation");
class ConsoleMessage extends instrumentation_1.SdkObject {
    constructor(parent, type, text, args, location) {
        super(parent, 'console-message');
        this._type = type;
        this._text = text;
        this._args = args;
        this._location = location || { url: '', lineNumber: 0, columnNumber: 0 };
    }
    type() {
        return this._type;
    }
    text() {
        if (this._text === undefined)
            this._text = this._args.map(arg => arg._value).join(' ');
        return this._text;
    }
    args() {
        return this._args;
    }
    location() {
        return this._location;
    }
}
exports.ConsoleMessage = ConsoleMessage;
//# sourceMappingURL=console.js.map