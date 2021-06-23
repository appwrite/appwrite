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
exports.isContextDestroyedError = exports.kSwappedOutErrorMessage = exports.normalizeEvaluationExpression = exports.parseUnserializableValue = exports.evaluateExpressionAndWaitForSignals = exports.evaluateExpression = exports.evaluate = exports.JSHandle = exports.ExecutionContext = void 0;
const utilityScriptSource = __importStar(require("../generated/utilityScriptSource"));
const utilityScriptSerializers_1 = require("./common/utilityScriptSerializers");
const instrumentation_1 = require("./instrumentation");
class ExecutionContext extends instrumentation_1.SdkObject {
    constructor(parent, delegate) {
        super(parent, 'execution-context');
        this._delegate = delegate;
    }
    async waitForSignalsCreatedBy(action) {
        return action();
    }
    adoptIfNeeded(handle) {
        return null;
    }
    utilityScript() {
        if (!this._utilityScriptPromise) {
            const source = `
      (() => {
        ${utilityScriptSource.source}
        return new pwExport();
      })();`;
            this._utilityScriptPromise = this._delegate.rawEvaluateHandle(source).then(objectId => new JSHandle(this, 'object', objectId));
        }
        return this._utilityScriptPromise;
    }
    createHandle(remoteObject) {
        return this._delegate.createHandle(this, remoteObject);
    }
    async rawEvaluateJSON(expression) {
        return await this._delegate.rawEvaluateJSON(expression);
    }
    async doSlowMo() {
        // overrided in FrameExecutionContext
    }
}
exports.ExecutionContext = ExecutionContext;
class JSHandle extends instrumentation_1.SdkObject {
    constructor(context, type, objectId, value) {
        super(context, 'handle');
        this._disposed = false;
        this._context = context;
        this._objectId = objectId;
        this._value = value;
        this._objectType = type;
        if (this._objectId)
            this._value = 'JSHandle@' + this._objectType;
        this._preview = 'JSHandle@' + String(this._objectId ? this._objectType : this._value);
    }
    callFunctionNoReply(func, arg) {
        this._context._delegate.rawCallFunctionNoReply(func, this, arg);
    }
    async evaluate(pageFunction, arg) {
        return evaluate(this._context, true /* returnByValue */, pageFunction, this, arg);
    }
    async evaluateHandle(pageFunction, arg) {
        return evaluate(this._context, false /* returnByValue */, pageFunction, this, arg);
    }
    async evaluateExpressionAndWaitForSignals(expression, isFunction, returnByValue, arg) {
        const value = await evaluateExpressionAndWaitForSignals(this._context, returnByValue, expression, isFunction, this, arg);
        await this._context.doSlowMo();
        return value;
    }
    async getProperty(propertyName) {
        const objectHandle = await this.evaluateHandle((object, propertyName) => {
            const result = { __proto__: null };
            result[propertyName] = object[propertyName];
            return result;
        }, propertyName);
        const properties = await objectHandle.getProperties();
        const result = properties.get(propertyName);
        objectHandle.dispose();
        return result;
    }
    async getProperties() {
        if (!this._objectId)
            return new Map();
        return this._context._delegate.getProperties(this._context, this._objectId);
    }
    rawValue() {
        return this._value;
    }
    async jsonValue() {
        if (!this._objectId)
            return this._value;
        const utilityScript = await this._context.utilityScript();
        const script = `(utilityScript, ...args) => utilityScript.jsonValue(...args)`;
        return this._context._delegate.evaluateWithArguments(script, true, utilityScript, [true], [this._objectId]);
    }
    asElement() {
        return null;
    }
    dispose() {
        if (this._disposed)
            return;
        this._disposed = true;
        if (this._objectId)
            this._context._delegate.releaseHandle(this._objectId).catch(e => { });
    }
    toString() {
        return this._preview;
    }
    _setPreviewCallback(callback) {
        this._previewCallback = callback;
    }
    _setPreview(preview) {
        this._preview = preview;
        if (this._previewCallback)
            this._previewCallback(preview);
    }
}
exports.JSHandle = JSHandle;
async function evaluate(context, returnByValue, pageFunction, ...args) {
    return evaluateExpression(context, returnByValue, String(pageFunction), typeof pageFunction === 'function', ...args);
}
exports.evaluate = evaluate;
async function evaluateExpression(context, returnByValue, expression, isFunction, ...args) {
    const utilityScript = await context.utilityScript();
    expression = normalizeEvaluationExpression(expression, isFunction);
    const handles = [];
    const toDispose = [];
    const pushHandle = (handle) => {
        handles.push(handle);
        return handles.length - 1;
    };
    args = args.map(arg => utilityScriptSerializers_1.serializeAsCallArgument(arg, handle => {
        if (handle instanceof JSHandle) {
            if (!handle._objectId)
                return { fallThrough: handle._value };
            if (handle._disposed)
                throw new Error('JSHandle is disposed!');
            const adopted = context.adoptIfNeeded(handle);
            if (adopted === null)
                return { h: pushHandle(Promise.resolve(handle)) };
            toDispose.push(adopted);
            return { h: pushHandle(adopted) };
        }
        return { fallThrough: handle };
    }));
    const utilityScriptObjectIds = [];
    for (const handle of await Promise.all(handles)) {
        if (handle._context !== context)
            throw new Error('JSHandles can be evaluated only in the context they were created!');
        utilityScriptObjectIds.push(handle._objectId);
    }
    // See UtilityScript for arguments.
    const utilityScriptValues = [isFunction, returnByValue, expression, args.length, ...args];
    const script = `(utilityScript, ...args) => utilityScript.evaluate(...args)`;
    try {
        return await context._delegate.evaluateWithArguments(script, returnByValue, utilityScript, utilityScriptValues, utilityScriptObjectIds);
    }
    finally {
        toDispose.map(handlePromise => handlePromise.then(handle => handle.dispose()));
    }
}
exports.evaluateExpression = evaluateExpression;
async function evaluateExpressionAndWaitForSignals(context, returnByValue, expression, isFunction, ...args) {
    return await context.waitForSignalsCreatedBy(() => evaluateExpression(context, returnByValue, expression, isFunction, ...args));
}
exports.evaluateExpressionAndWaitForSignals = evaluateExpressionAndWaitForSignals;
function parseUnserializableValue(unserializableValue) {
    if (unserializableValue === 'NaN')
        return NaN;
    if (unserializableValue === 'Infinity')
        return Infinity;
    if (unserializableValue === '-Infinity')
        return -Infinity;
    if (unserializableValue === '-0')
        return -0;
}
exports.parseUnserializableValue = parseUnserializableValue;
function normalizeEvaluationExpression(expression, isFunction) {
    expression = expression.trim();
    if (isFunction) {
        try {
            new Function('(' + expression + ')');
        }
        catch (e1) {
            // This means we might have a function shorthand. Try another
            // time prefixing 'function '.
            if (expression.startsWith('async '))
                expression = 'async function ' + expression.substring('async '.length);
            else
                expression = 'function ' + expression;
            try {
                new Function('(' + expression + ')');
            }
            catch (e2) {
                // We tried hard to serialize, but there's a weird beast here.
                throw new Error('Passed function is not well-serializable!');
            }
        }
    }
    if (/^(async)?\s*function(\s|\()/.test(expression))
        expression = '(' + expression + ')';
    return expression;
}
exports.normalizeEvaluationExpression = normalizeEvaluationExpression;
exports.kSwappedOutErrorMessage = 'Target was swapped out.';
function isContextDestroyedError(e) {
    if (!e || typeof e !== 'object' || typeof e.message !== 'string')
        return false;
    // Evaluating in a context which was already destroyed.
    if (e.message.includes('Cannot find context with specified id')
        || e.message.includes('Failed to find execution context with id')
        || e.message.includes('Missing injected script for given')
        || e.message.includes('Cannot find object with id'))
        return true;
    // Evaluation promise is rejected when context is gone.
    if (e.message.includes('Execution context was destroyed'))
        return true;
    // WebKit target swap.
    if (e.message.includes(exports.kSwappedOutErrorMessage))
        return true;
    return false;
}
exports.isContextDestroyedError = isContextDestroyedError;
//# sourceMappingURL=javascript.js.map