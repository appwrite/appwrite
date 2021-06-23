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
exports.CRExecutionContext = void 0;
const crProtocolHelper_1 = require("./crProtocolHelper");
const js = __importStar(require("../javascript"));
const stackTrace_1 = require("../../utils/stackTrace");
const utilityScriptSerializers_1 = require("../common/utilityScriptSerializers");
class CRExecutionContext {
    constructor(client, contextPayload) {
        this._client = client;
        this._contextId = contextPayload.id;
    }
    async rawEvaluateJSON(expression) {
        const { exceptionDetails, result: remoteObject } = await this._client.send('Runtime.evaluate', {
            expression,
            contextId: this._contextId,
            returnByValue: true,
        }).catch(rewriteError);
        if (exceptionDetails)
            throw new Error('Evaluation failed: ' + crProtocolHelper_1.getExceptionMessage(exceptionDetails));
        return remoteObject.value;
    }
    async rawEvaluateHandle(expression) {
        const { exceptionDetails, result: remoteObject } = await this._client.send('Runtime.evaluate', {
            expression,
            contextId: this._contextId,
        }).catch(rewriteError);
        if (exceptionDetails)
            throw new Error('Evaluation failed: ' + crProtocolHelper_1.getExceptionMessage(exceptionDetails));
        return remoteObject.objectId;
    }
    rawCallFunctionNoReply(func, ...args) {
        this._client.send('Runtime.callFunctionOn', {
            functionDeclaration: func.toString(),
            arguments: args.map(a => a instanceof js.JSHandle ? { objectId: a._objectId } : { value: a }),
            returnByValue: true,
            executionContextId: this._contextId,
            userGesture: true
        }).catch(() => { });
    }
    async evaluateWithArguments(expression, returnByValue, utilityScript, values, objectIds) {
        const { exceptionDetails, result: remoteObject } = await this._client.send('Runtime.callFunctionOn', {
            functionDeclaration: expression,
            objectId: utilityScript._objectId,
            arguments: [
                { objectId: utilityScript._objectId },
                ...values.map(value => ({ value })),
                ...objectIds.map(objectId => ({ objectId })),
            ],
            returnByValue,
            awaitPromise: true,
            userGesture: true
        }).catch(rewriteError);
        if (exceptionDetails)
            throw new Error('Evaluation failed: ' + crProtocolHelper_1.getExceptionMessage(exceptionDetails));
        return returnByValue ? utilityScriptSerializers_1.parseEvaluationResultValue(remoteObject.value) : utilityScript._context.createHandle(remoteObject);
    }
    async getProperties(context, objectId) {
        const response = await this._client.send('Runtime.getProperties', {
            objectId,
            ownProperties: true
        });
        const result = new Map();
        for (const property of response.result) {
            if (!property.enumerable || !property.value)
                continue;
            result.set(property.name, context.createHandle(property.value));
        }
        return result;
    }
    createHandle(context, remoteObject) {
        return new js.JSHandle(context, remoteObject.subtype || remoteObject.type, remoteObject.objectId, potentiallyUnserializableValue(remoteObject));
    }
    async releaseHandle(objectId) {
        await crProtocolHelper_1.releaseObject(this._client, objectId);
    }
}
exports.CRExecutionContext = CRExecutionContext;
function rewriteError(error) {
    if (error.message.includes('Object reference chain is too long'))
        return { result: { type: 'undefined' } };
    if (error.message.includes('Object couldn\'t be returned by value'))
        return { result: { type: 'undefined' } };
    if (js.isContextDestroyedError(error) || error.message.endsWith('Inspected target navigated or closed'))
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
//# sourceMappingURL=crExecutionContext.js.map