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
exports.WKExecutionContext = void 0;
const js = __importStar(require("../javascript"));
const utilityScriptSerializers_1 = require("../common/utilityScriptSerializers");
class WKExecutionContext {
    constructor(session, contextId) {
        this._contextDestroyedCallback = () => { };
        this._session = session;
        this._contextId = contextId;
        this._executionContextDestroyedPromise = new Promise((resolve, reject) => {
            this._contextDestroyedCallback = resolve;
        });
    }
    _dispose() {
        this._contextDestroyedCallback();
    }
    async rawEvaluateJSON(expression) {
        try {
            const response = await this._session.send('Runtime.evaluate', {
                expression,
                contextId: this._contextId,
                returnByValue: true
            });
            if (response.wasThrown)
                throw new Error('Evaluation failed: ' + response.result.description);
            return response.result.value;
        }
        catch (error) {
            throw rewriteError(error);
        }
    }
    async rawEvaluateHandle(expression) {
        try {
            const response = await this._session.send('Runtime.evaluate', {
                expression,
                contextId: this._contextId,
                returnByValue: false
            });
            if (response.wasThrown)
                throw new Error('Evaluation failed: ' + response.result.description);
            return response.result.objectId;
        }
        catch (error) {
            throw rewriteError(error);
        }
    }
    rawCallFunctionNoReply(func, ...args) {
        this._session.send('Runtime.callFunctionOn', {
            functionDeclaration: func.toString(),
            objectId: args.find(a => a instanceof js.JSHandle)._objectId,
            arguments: args.map(a => a instanceof js.JSHandle ? { objectId: a._objectId } : { value: a }),
            returnByValue: true,
            emulateUserGesture: true
        }).catch(() => { });
    }
    async evaluateWithArguments(expression, returnByValue, utilityScript, values, objectIds) {
        try {
            const response = await Promise.race([
                this._executionContextDestroyedPromise.then(() => contextDestroyedResult),
                this._session.send('Runtime.callFunctionOn', {
                    functionDeclaration: expression,
                    objectId: utilityScript._objectId,
                    arguments: [
                        { objectId: utilityScript._objectId },
                        ...values.map(value => ({ value })),
                        ...objectIds.map(objectId => ({ objectId })),
                    ],
                    returnByValue,
                    emulateUserGesture: true,
                    awaitPromise: true
                })
            ]);
            if (response.wasThrown)
                throw new Error('Evaluation failed: ' + response.result.description);
            if (returnByValue)
                return utilityScriptSerializers_1.parseEvaluationResultValue(response.result.value);
            return utilityScript._context.createHandle(response.result);
        }
        catch (error) {
            throw rewriteError(error);
        }
    }
    async getProperties(context, objectId) {
        const response = await this._session.send('Runtime.getProperties', {
            objectId,
            ownProperties: true
        });
        const result = new Map();
        for (const property of response.properties) {
            if (!property.enumerable || !property.value)
                continue;
            result.set(property.name, context.createHandle(property.value));
        }
        return result;
    }
    createHandle(context, remoteObject) {
        const isPromise = remoteObject.className === 'Promise';
        return new js.JSHandle(context, isPromise ? 'promise' : remoteObject.subtype || remoteObject.type, remoteObject.objectId, potentiallyUnserializableValue(remoteObject));
    }
    async releaseHandle(objectId) {
        await this._session.send('Runtime.releaseObject', { objectId });
    }
}
exports.WKExecutionContext = WKExecutionContext;
const contextDestroyedResult = {
    wasThrown: true,
    result: {
        description: 'Protocol error: Execution context was destroyed, most likely because of a navigation.'
    }
};
function potentiallyUnserializableValue(remoteObject) {
    const value = remoteObject.value;
    const isUnserializable = remoteObject.type === 'number' && ['NaN', '-Infinity', 'Infinity', '-0'].includes(remoteObject.description);
    return isUnserializable ? js.parseUnserializableValue(remoteObject.description) : value;
}
function rewriteError(error) {
    if (js.isContextDestroyedError(error))
        return new Error('Execution context was destroyed, most likely because of a navigation.');
    return error;
}
//# sourceMappingURL=wkExecutionContext.js.map