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
exports.tEnum = exports.tObject = exports.tArray = exports.tOptional = exports.tAny = exports.tUndefined = exports.tBinary = exports.tString = exports.tBoolean = exports.tNumber = exports.ValidationError = void 0;
const utils_1 = require("../utils/utils");
class ValidationError extends Error {
}
exports.ValidationError = ValidationError;
const tNumber = (arg, path) => {
    if (arg instanceof Number)
        return arg.valueOf();
    if (typeof arg === 'number')
        return arg;
    throw new ValidationError(`${path}: expected number, got ${typeof arg}`);
};
exports.tNumber = tNumber;
const tBoolean = (arg, path) => {
    if (arg instanceof Boolean)
        return arg.valueOf();
    if (typeof arg === 'boolean')
        return arg;
    throw new ValidationError(`${path}: expected boolean, got ${typeof arg}`);
};
exports.tBoolean = tBoolean;
const tString = (arg, path) => {
    if (arg instanceof String)
        return arg.valueOf();
    if (typeof arg === 'string')
        return arg;
    throw new ValidationError(`${path}: expected string, got ${typeof arg}`);
};
exports.tString = tString;
const tBinary = (arg, path) => {
    if (arg instanceof String)
        return arg.valueOf();
    if (typeof arg === 'string')
        return arg;
    throw new ValidationError(`${path}: expected base64-encoded buffer, got ${typeof arg}`);
};
exports.tBinary = tBinary;
const tUndefined = (arg, path) => {
    if (Object.is(arg, undefined))
        return arg;
    throw new ValidationError(`${path}: expected undefined, got ${typeof arg}`);
};
exports.tUndefined = tUndefined;
const tAny = (arg, path) => {
    return arg;
};
exports.tAny = tAny;
const tOptional = (v) => {
    return (arg, path) => {
        if (Object.is(arg, undefined))
            return arg;
        return v(arg, path);
    };
};
exports.tOptional = tOptional;
const tArray = (v) => {
    return (arg, path) => {
        if (!Array.isArray(arg))
            throw new ValidationError(`${path}: expected array, got ${typeof arg}`);
        return arg.map((x, index) => v(x, path + '[' + index + ']'));
    };
};
exports.tArray = tArray;
const tObject = (s) => {
    return (arg, path) => {
        if (Object.is(arg, null))
            throw new ValidationError(`${path}: expected object, got null`);
        if (typeof arg !== 'object')
            throw new ValidationError(`${path}: expected object, got ${typeof arg}`);
        const result = {};
        for (const [key, v] of Object.entries(s)) {
            const value = v(arg[key], path ? path + '.' + key : key);
            if (!Object.is(value, undefined))
                result[key] = value;
        }
        if (utils_1.isUnderTest()) {
            for (const [key, value] of Object.entries(arg)) {
                if (key.startsWith('__testHook'))
                    result[key] = value;
            }
        }
        return result;
    };
};
exports.tObject = tObject;
const tEnum = (e) => {
    return (arg, path) => {
        if (!e.includes(arg))
            throw new ValidationError(`${path}: expected one of (${e.join('|')})`);
        return arg;
    };
};
exports.tEnum = tEnum;
//# sourceMappingURL=validatorPrimitives.js.map