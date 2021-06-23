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
exports.ElectronApplicationDispatcher = exports.ElectronDispatcher = void 0;
const dispatcher_1 = require("./dispatcher");
const electron_1 = require("../server/electron/electron");
const browserContextDispatcher_1 = require("./browserContextDispatcher");
const jsHandleDispatcher_1 = require("./jsHandleDispatcher");
const elementHandlerDispatcher_1 = require("./elementHandlerDispatcher");
class ElectronDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, electron) {
        super(scope, electron, 'Electron', {}, true);
    }
    async launch(params) {
        const electronApplication = await this._object.launch(params);
        return { electronApplication: new ElectronApplicationDispatcher(this._scope, electronApplication) };
    }
}
exports.ElectronDispatcher = ElectronDispatcher;
class ElectronApplicationDispatcher extends dispatcher_1.Dispatcher {
    constructor(scope, electronApplication) {
        super(scope, electronApplication, 'ElectronApplication', {
            context: new browserContextDispatcher_1.BrowserContextDispatcher(scope, electronApplication.context())
        }, true);
        electronApplication.on(electron_1.ElectronApplication.Events.Close, () => {
            this._dispatchEvent('close');
            this._dispose();
        });
    }
    async browserWindow(params) {
        const handle = await this._object.browserWindow(params.page.page());
        return { handle: elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(this._scope, handle) };
    }
    async evaluateExpression(params) {
        const handle = await this._object._nodeElectronHandlePromise;
        return { value: jsHandleDispatcher_1.serializeResult(await handle.evaluateExpressionAndWaitForSignals(params.expression, params.isFunction, true /* returnByValue */, jsHandleDispatcher_1.parseArgument(params.arg))) };
    }
    async evaluateExpressionHandle(params) {
        const handle = await this._object._nodeElectronHandlePromise;
        const result = await handle.evaluateExpressionAndWaitForSignals(params.expression, params.isFunction, false /* returnByValue */, jsHandleDispatcher_1.parseArgument(params.arg));
        return { handle: elementHandlerDispatcher_1.ElementHandleDispatcher.fromJSHandle(this._scope, result) };
    }
    async close() {
        await this._object.close();
    }
}
exports.ElectronApplicationDispatcher = ElectronApplicationDispatcher;
//# sourceMappingURL=electronDispatcher.js.map