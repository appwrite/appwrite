"use strict";
/**
 * Copyright 2019 Google Inc. All rights reserved.
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
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    Object.defineProperty(o, k2, { enumerable: true, get: function() { return m[k]; } });
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || function (mod) {
    if (mod && mod.__esModule) return mod;
    var result = {};
    if (mod != null) for (var k in mod) if (k !== "default" && Object.prototype.hasOwnProperty.call(mod, k)) __createBinding(result, mod, k);
    __setModuleDefault(result, mod);
    return result;
};
Object.defineProperty(exports, "__esModule", { value: true });
exports.FFExecutionContext = void 0;
const js = __importStar(require("../javascript"));
const stackTrace_1 = require("../../utils/stackTrace");
const utilityScriptSerializers_1 = require("../common/utilityScriptSerializers");
class FFExecutionContext {
    constructor(session, executionContextId) {
        this._session = session;
        this._executionContextId = executionContextId;
    }
    async rawEvaluateJSON(expression) {
        const payload = await this._session.send('Runtime.evaluate', {
            expression,
            returnByValue: true,
            executionContextId: this._executionContextId,
        }).catch(rewriteError);
        checkException(payload.exceptionDetails);
        return payload.result.value;
    }
    async rawEvaluateHandle(expression) {
        const payload = await this._session.send('Runtime.evaluate', {
            expression,
            returnByValue: false,
            executionContextId: this._executionContextId,
        }).catch(rewriteError);
        checkException(payload.exceptionDetails);
        return payload.result.objectId;
    }
    rawCallFunctionNoReply(func, ...args) {
        this._session.send('Runtime.callFunction', {
            functionDeclaration: func.toString(),
            args: args.map(a => a instanceof js.JSHandle ? { objectId: a._objectId } : { value: a }),
            returnByValue: true,
            executionContextId: this._executionContextId
        }).catch(() => { });
    }
    async evaluateWithArguments(expression, returnByValue, utilityScript, values, objectIds) {
        const payload = await this._session.send('Runtime.callFunction', {
            functionDeclaration: expression,
            args: [
                { objectId: utilityScript._objectId, value: undefined },
                ...values.map(value => ({ value })),
                ...objectIds.map(objectId => ({ objectId, value: undefined })),
            ],
            returnByValue,
            executionContextId: this._executionContextId
        }).catch(rewriteError);
        checkException(payload.exceptionDetails);
        if (returnByValue)
            return utilityScriptSerializers_1.parseEvaluationResultValue(payload.result.value);
        return utilityScript._context.createHandle(payload.result);
    }
    async getProperties(context, objectId) {
        const response = await this._session.send('Runtime.getObjectProperties', {
            executionContextId: this._executionContextId,
            objectId,
        });
        const result = new Map();
        for (const property of response.properties)
            result.set(property.name, context.createHandle(property.value));
        return result;
    }
    createHandle(context, remoteObject) {
        return new js.JSHandle(context, remoteObject.subtype || remoteObject.type || '', remoteObject.objectId, potentiallyUnserializableValue(remoteObject));
    }
    async releaseHandle(objectId) {
        await this._session.send('Runtime.disposeObject', {
            executionContextId: this._executionContextId,
            objectId
        });
    }
}
exports.FFExecutionContext = FFExecutionContext;
function checkException(exceptionDetails) {
    if (!exceptionDetails)
        return;
    if (exceptionDetails.value)
        throw new Error('Evaluation failed: ' + JSON.stringify(exceptionDetails.value));
    else
        throw new Error('Evaluation failed: ' + exceptionDetails.text + '\n' + exceptionDetails.stack);
}
function rewriteError(error) {
    if (error.message.includes('cyclic object value') || error.message.includes('Object is not serializable'))
        return { result: { type: 'undefined', value: undefined } };
    if (js.isContextDestroyedError(error))
        throw new Error('Execution context was destroyed, most likely because of a navigation.');
    if (error instanceof TypeError && error.message.startsWith('Converting circular structure to JSON'))
        stackTrace_1.rewriteErrorMessage(error, error.message + ' Are you passing a nested JSHandle?');
    throw error;
}
function potentiallyUnserializableValue(remoteObject) {
    const value = remoteObject.value;
    const unserializableValue = remoteObject.unserializableValue;
    return unserializableValue ? js.parseUnserializableValue(unserializableValue) : value;
}
//# sourceMappingURL=ffExecutionContext.js.map